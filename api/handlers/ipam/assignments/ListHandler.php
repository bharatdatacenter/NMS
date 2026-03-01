<?php

declare(strict_types=1);

/**
 * GET /api/ipam/assignments
 *
 * List IP assignments.
 * Filters: pool_id, ip_version, status, assigned_to_id, search
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $allocator = new IPAllocator();

    $filters = array_filter([
        'pool_id'        => $params['pool_id'] ?? null,
        'ip_version'     => $params['ip_version'] ?? null,
        'status'         => $params['status'] ?? null,
        'assigned_to_id' => $params['assigned_to_id'] ?? null,
        'search'         => $params['search'] ?? null,
    ]);
    $page    = max(1, (int) ($params['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

    $result = $allocator->list($filters, $page, $perPage);

    Response::json($result);

} catch (\Exception $e) {
    Response::error('Failed to list assignments: ' . $e->getMessage(), 500);
}
