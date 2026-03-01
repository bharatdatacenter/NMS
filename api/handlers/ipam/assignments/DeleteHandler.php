<?php

declare(strict_types=1);

/**
 * DELETE /api/ipam/assignments/{ip}
 *
 * Release an IP assignment (sets status to 'released', decrements pool counters).
 * This does NOT delete the document — it marks it as released for future reuse.
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;

try {
    $allocator  = new IPAllocator();
    $assignment = $allocator->findByIp($params['ip']);

    if (!$assignment) {
        Response::notFound('Assignment not found for IP ' . $params['ip']);
    }

    if (($assignment['status'] ?? '') !== 'active') {
        Response::unprocessable('Cannot release an IP that is not active', [
            'status' => "Current status: {$assignment['status']}",
        ]);
    }

    $released = $allocator->release($params['ip']);

    Response::json([
        'message'  => 'IP released successfully',
        'ip'       => $params['ip'],
        'released' => $released,
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to release assignment: ' . $e->getMessage(), 500);
}
