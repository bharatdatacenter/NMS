<?php

declare(strict_types=1);

/**
 * GET /api/ipam/search
 *
 * Search across pools and assignments by IP, hostname, or MAC address.
 * Returns matching assignments and the pools they belong to.
 *
 * Query param: q (search string — IP, hostname, or MAC)
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $q = trim($params['q'] ?? '');
    if (strlen($q) < 2) {
        Response::unprocessable('Search query too short', ['q' => 'Must be at least 2 characters']);
    }

    $allocator = new IPAllocator();
    $page      = max(1, (int) ($params['page'] ?? 1));
    $perPage   = min(100, max(1, (int) ($params['per_page'] ?? 20)));

    $result = $allocator->list(['search' => $q], $page, $perPage);

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Search failed: ' . $e->getMessage(), 500);
}
