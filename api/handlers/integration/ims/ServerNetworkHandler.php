<?php

declare(strict_types=1);

/**
 * GET /api/integration/ims/server/{id}/network
 *
 * Return the complete network profile for an IMS server.
 * Aggregates IP assignments, routes, firewall policies, and NIC info.
 */

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use NMS\Core\Helpers\Response;

try {
    $serverId = (string)($params['id'] ?? '');
    if ($serverId === '') {
        Response::notFound('Server ID is required');
    }

    $db = MongoDB::getInstance();

    // IP assignments for this server
    $assignmentsCursor = $db->selectCollection('ip_assignments')->find([
        'assigned_to.id' => $serverId,
        'status'         => 'active',
    ]);
    $assignments = [];
    foreach ($assignmentsCursor as $doc) {
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        }
        $assignments[] = $arr;
    }

    // Gather route IDs and neighbor IDs from assignments
    $routeIds    = [];
    $neighborIds = [];
    $policyIds   = [];
    $allIps      = array_column($assignments, 'ip_address');

    foreach ($assignments as $a) {
        if (!empty($a['routing']['route_id']['$oid'])) {
            $routeIds[] = new ObjectId($a['routing']['route_id']['$oid']);
        }
        if (!empty($a['routing']['neighbor_id']['$oid'])) {
            $neighborIds[] = new ObjectId($a['routing']['neighbor_id']['$oid']);
        }
        foreach ((array)($a['firewall_policy_ids'] ?? []) as $pid) {
            $oid = is_array($pid) ? ($pid['$oid'] ?? '') : (string)$pid;
            if ($oid !== '') {
                $policyIds[] = new ObjectId($oid);
            }
        }
    }

    // Static routes
    $routes = [];
    if (!empty($routeIds)) {
        foreach ($db->selectCollection('static_routes')->find(['_id' => ['$in' => $routeIds]]) as $doc) {
            $arr = json_decode(json_encode($doc), true);
            if (isset($arr['_id']['$oid'])) {
                $arr['id'] = $arr['_id']['$oid'];
                unset($arr['_id']);
            }
            $routes[] = $arr;
        }
    }

    // Neighbor entries
    $neighbors = [];
    if (!empty($neighborIds)) {
        foreach ($db->selectCollection('neighbor_entries')->find(['_id' => ['$in' => $neighborIds]]) as $doc) {
            $arr = json_decode(json_encode($doc), true);
            if (isset($arr['_id']['$oid'])) {
                $arr['id'] = $arr['_id']['$oid'];
                unset($arr['_id']);
            }
            $neighbors[] = $arr;
        }
    }

    // Firewall policies
    $policies = [];
    if (!empty($policyIds)) {
        foreach ($db->selectCollection('firewall_policies')->find(['_id' => ['$in' => $policyIds]]) as $doc) {
            $arr = json_decode(json_encode($doc), true);
            if (isset($arr['_id']['$oid'])) {
                $arr['id'] = $arr['_id']['$oid'];
                unset($arr['_id']);
            }
            $policies[] = $arr;
        }
    }

    // NICs
    $nicsCursor = $db->selectCollection('server_nics')->find(['ims_server_id' => $serverId]);
    $nics       = [];
    foreach ($nicsCursor as $doc) {
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        }
        $nics[] = $arr;
    }

    Response::json([
        'data' => [
            'server_id'   => $serverId,
            'assignments' => $assignments,
            'routes'      => $routes,
            'neighbors'   => $neighbors,
            'policies'    => $policies,
            'nics'        => $nics,
            'ip_summary'  => [
                'ipv4' => array_values(array_filter($allIps, fn($ip) => str_contains($ip, '.'))),
                'ipv6' => array_values(array_filter($allIps, fn($ip) => str_contains($ip, ':'))),
            ],
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to load server network: ' . $e->getMessage(), 500);
}
