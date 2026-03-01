<?php

declare(strict_types=1);

/**
 * GET /api/ipam/assignments/{ip}
 *
 * Get a single IP assignment by IP address.
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $allocator  = new IPAllocator();
    $assignment = $allocator->findByIp($params['ip']);

    if (!$assignment) {
        Response::notFound('Assignment not found for IP ' . $params['ip']);
    }

    Response::json(['data' => $assignment]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to get assignment: ' . $e->getMessage(), 500);
}
