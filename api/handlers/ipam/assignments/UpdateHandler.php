<?php

declare(strict_types=1);

/**
 * PUT /api/ipam/assignments/{ip}
 *
 * Update mutable metadata on an assignment.
 * Allowed fields: hostname, description, notes, tags, reverse_dns, status
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $allocator  = new IPAllocator();
    $assignment = $allocator->findByIp($params['ip']);

    if (!$assignment) {
        Response::notFound('Assignment not found for IP ' . $params['ip']);
    }

    $updated = $allocator->update($params['ip'], $body);

    Response::json([
        'data'    => $allocator->findByIp($params['ip']),
        'updated' => $updated,
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to update assignment: ' . $e->getMessage(), 500);
}
