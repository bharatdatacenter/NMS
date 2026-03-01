<?php

declare(strict_types=1);

/**
 * POST /api/ipam/assignments/next
 *
 * Atomically allocate the next available IP from a pool.
 *
 * Required: pool_id, assigned_to (type, id, name)
 * Optional: mac_address, assignment_type (layer2|layer3)
 *
 * Uses X-Idempotency-Key for safe retries (handled by IdempotencyMiddleware).
 * Uses atomic findOneAndUpdate — race-condition safe.
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'pool_id'     => 'required|string',
        'assigned_to' => 'required',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validTypes = ['layer2', 'layer3'];
    $assignType = $body['assignment_type'] ?? 'layer2';
    if (!in_array($assignType, $validTypes, true)) {
        Response::unprocessable('Invalid assignment_type', ['assignment_type' => 'Must be layer2 or layer3']);
    }

    $allocator  = new IPAllocator();
    $assignment = $allocator->allocateNext(
        $body['pool_id'],
        $body['assigned_to'],
        $body['mac_address'] ?? '',
        $assignType
    );

    Response::json(['data' => $assignment], 201);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to allocate next IP: ' . $e->getMessage(), 500);
}
