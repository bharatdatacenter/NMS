<?php

declare(strict_types=1);

/**
 * POST /api/ipam/pools
 *
 * Create a new IP pool.
 *
 * Required: name, network
 * Optional: block_id, ip_version, gateway_ip, pool_type, allocation_type, vlan_id,
 *           site_id, datacenter, router_device_id, router_cluster_id, interface_name,
 *           description, auto_assign, status
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Models\Ipam\ConflictChecker;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Helpers\IPUtils;

try {
    $v = new Validator();
    $v->validate($body, [
        'name'    => 'required|string',
        'network' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate CIDR
    try {
        IPUtils::parseCIDR($body['network']);
    } catch (\InvalidArgumentException $e) {
        Response::unprocessable('Invalid CIDR', ['network' => $e->getMessage()]);
    }

    // Validate enums
    $validPoolTypes  = ['public', 'private', 'management', 'vpn', 'infrastructure'];
    $validAllocTypes = ['layer2', 'layer3', 'mixed'];
    if (isset($body['pool_type']) && !in_array($body['pool_type'], $validPoolTypes, true)) {
        Response::unprocessable('Invalid pool_type', ['pool_type' => 'Must be one of: ' . implode(', ', $validPoolTypes)]);
    }
    if (isset($body['allocation_type']) && !in_array($body['allocation_type'], $validAllocTypes, true)) {
        Response::unprocessable('Invalid allocation_type', ['allocation_type' => 'Must be one of: ' . implode(', ', $validAllocTypes)]);
    }

    // Check pool overlap within block if block_id is provided
    if (!empty($body['block_id'])) {
        $checker = new ConflictChecker();
        if ($checker->checkPoolOverlap($body['network'], $body['block_id'])) {
            Response::unprocessable('Pool conflict', ['network' => 'Overlaps with an existing pool in this block']);
        }
    }

    $manager = new PoolManager();
    $id      = $manager->createPool($body);
    $pool    = $manager->findPoolById($id);

    Response::json(['data' => $pool], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create pool: ' . $e->getMessage(), 500);
}
