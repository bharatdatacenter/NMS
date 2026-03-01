<?php

declare(strict_types=1);

/**
 * PUT /api/ipam/pools/{id}
 *
 * Update a pool's mutable fields.
 * name, gateway_ip, description, status, auto_assign, pool_type,
 * allocation_type, vlan_id, interface_name, datacenter,
 * site_id, router_device_id, router_cluster_id
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $pool    = $manager->findPoolById($params['id']);

    if (!$pool) {
        Response::notFound('Pool not found');
    }

    $validPoolTypes  = ['public', 'private', 'management', 'vpn', 'infrastructure'];
    $validAllocTypes = ['layer2', 'layer3', 'mixed'];
    $validStatuses   = ['active', 'full', 'reserved', 'deprecated'];

    if (isset($body['pool_type']) && !in_array($body['pool_type'], $validPoolTypes, true)) {
        Response::unprocessable('Invalid pool_type', ['pool_type' => 'Must be one of: ' . implode(', ', $validPoolTypes)]);
    }
    if (isset($body['allocation_type']) && !in_array($body['allocation_type'], $validAllocTypes, true)) {
        Response::unprocessable('Invalid allocation_type', ['allocation_type' => 'Must be one of: ' . implode(', ', $validAllocTypes)]);
    }
    if (isset($body['status']) && !in_array($body['status'], $validStatuses, true)) {
        Response::unprocessable('Invalid status', ['status' => 'Must be one of: ' . implode(', ', $validStatuses)]);
    }

    $updated = $manager->updatePool($params['id'], $body);

    Response::json(['data' => $manager->findPoolById($params['id']), 'updated' => $updated]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to update pool: ' . $e->getMessage(), 500);
}
