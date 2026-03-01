<?php

declare(strict_types=1);

/**
 * GET /api/ipam/pools
 *
 * List IP pools.
 * Supports filters: ip_version (ipv4|ipv6), site_id, block_id, status, pool_type
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();

    $filters = array_filter([
        'ip_version' => $params['ip_version'] ?? null,
        'site_id'    => $params['site_id'] ?? null,
        'block_id'   => $params['block_id'] ?? null,
        'status'     => $params['status'] ?? null,
        'pool_type'  => $params['pool_type'] ?? null,
    ]);
    $page    = max(1, (int) ($params['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

    $result = $manager->listPools($filters, $page, $perPage);

    Response::json($result);

} catch (\Exception $e) {
    Response::error('Failed to list pools: ' . $e->getMessage(), 500);
}
