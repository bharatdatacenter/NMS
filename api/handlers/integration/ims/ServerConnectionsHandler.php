<?php

declare(strict_types=1);

/**
 * GET /api/integration/ims/server/{id}/connections
 *
 * Return the physical connectivity path for each NIC of an IMS server.
 * Shows NIC → patch panel → switch port cable path.
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

    // Load NICs for this server
    $nicsCursor = $db->selectCollection('server_nics')->find(['ims_server_id' => $serverId]);
    $connections = [];

    foreach ($nicsCursor as $nicDoc) {
        $nic = json_decode(json_encode($nicDoc), true);
        $nicId = $nic['_id']['$oid'] ?? '';

        $connection = [
            'nic_id'      => $nicId,
            'nic_name'    => $nic['nic_name'] ?? '',
            'mac_address' => $nic['mac_address'] ?? '',
            'vlan_id'     => $nic['vlan_id'] ?? null,
            'status'      => $nic['status'] ?? 'unknown',
            'connected_to'=> $nic['connected_to'] ?? null,
            'cable_path'  => null,
        ];

        // If NIC is connected via cable, resolve cable path from connectivity_paths
        $cableId = null;
        if (!empty($nic['connected_to']['cable_id'])) {
            $cableIdRaw = $nic['connected_to']['cable_id'];
            $cableId    = is_array($cableIdRaw) ? ($cableIdRaw['$oid'] ?? '') : (string)$cableIdRaw;
        }

        if ($cableId !== '') {
            $path = $db->selectCollection('connectivity_paths')->findOne([
                '$or' => [
                    ['port_a.cable_id' => new ObjectId($cableId)],
                    ['port_b.cable_id' => new ObjectId($cableId)],
                ],
            ]);
            if ($path !== null) {
                $pathArr = json_decode(json_encode($path), true);
                $connection['cable_path'] = $pathArr['path'] ?? null;
            }
        }

        $connections[] = $connection;
    }

    Response::json([
        'data' => [
            'server_id'   => $serverId,
            'connections' => $connections,
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to load server connections: ' . $e->getMessage(), 500);
}
