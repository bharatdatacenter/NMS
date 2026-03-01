<?php

declare(strict_types=1);

/**
 * POST /api/clusters
 *
 * Create a new device cluster (HA pair, VRRP group, switch stack).
 *
 * Required: name, vendor, management_ip, members[]
 * Each member:  {device_id, node_ip, role: primary|secondary|member}
 * Optional:     type (ha_pair|stack|vrrp), site_id, notes, tags
 *
 * Requires: nms.cluster.write permission
 */

use NMS\Core\Models\Devices\ClusterManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'name'          => 'required|string',
        'vendor'        => 'required|string',
        'management_ip' => 'required|string',
        'members'       => 'required',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate management IP
    if (!filter_var($body['management_ip'], FILTER_VALIDATE_IP)) {
        Response::unprocessable('Validation failed', ['management_ip' => 'Must be a valid IP address']);
    }

    // Validate members
    $members = $body['members'] ?? [];
    if (!is_array($members) || count($members) < 2) {
        Response::unprocessable('Validation failed', ['members' => 'At least 2 members required']);
    }

    $validRoles = ['primary', 'secondary', 'member'];
    foreach ($members as $i => $member) {
        if (empty($member['device_id'])) {
            Response::unprocessable('Validation failed', ["members.{$i}.device_id" => 'Required']);
        }
        if (empty($member['node_ip']) || !filter_var($member['node_ip'], FILTER_VALIDATE_IP)) {
            Response::unprocessable('Validation failed', ["members.{$i}.node_ip" => 'Must be a valid IP address']);
        }
        if (isset($member['role']) && !in_array($member['role'], $validRoles, true)) {
            Response::unprocessable('Validation failed', ["members.{$i}.role" => 'Must be: ' . implode(', ', $validRoles)]);
        }
    }

    $validTypes = ['ha_pair', 'stack', 'vrrp'];
    if (isset($body['type']) && !in_array($body['type'], $validTypes, true)) {
        Response::unprocessable('Validation failed', ['type' => 'Must be: ' . implode(', ', $validTypes)]);
    }

    $manager   = new ClusterManager();
    $clusterId = $manager->create($body, $request['user']->sub ?? 'system');

    $cluster = $manager->findById($clusterId);
    Response::json(['data' => $cluster], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create cluster: ' . $e->getMessage(), 500);
}
