<?php

declare(strict_types=1);

/**
 * GET /api/ipam/assignments/{ip}/history
 *
 * Get the full assignment history for an IP address (most recent first).
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $allocator = new IPAllocator();
    $page      = max(1, (int) ($params['page'] ?? 1));
    $perPage   = min(200, max(1, (int) ($params['per_page'] ?? 50)));

    $result = $allocator->getHistory($params['ip'], $page, $perPage);

    Response::json($result);

} catch (\Exception $e) {
    Response::error('Failed to get assignment history: ' . $e->getMessage(), 500);
}
