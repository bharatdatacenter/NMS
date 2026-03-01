<?php

declare(strict_types=1);

/**
 * GET /api/ipam/blocks
 *
 * List IP blocks (top-level allocations from RIRs).
 * Supports filters: ip_version (ipv4|ipv6), status, search
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();

    $filters = [
        'ip_version' => $params['ip_version'] ?? null,
        'status'     => $params['status'] ?? null,
        'search'     => $params['search'] ?? null,
    ];
    $page    = max(1, (int) ($params['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

    $result = $manager->listBlocks(array_filter($filters), $page, $perPage);

    Response::json($result);

} catch (\Exception $e) {
    Response::error('Failed to list IP blocks: ' . $e->getMessage(), 500);
}
