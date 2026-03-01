<?php

declare(strict_types=1);

/**
 * POST /api/ipam/assignments
 *
 * Assign a specific IP address from a pool.
 *
 * Required: ip_address, pool_id, assigned_to (type, id, name)
 * Optional: assignment_type (layer2|layer3), mac_address
 */

use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Ipam\ConflictChecker;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'ip_address' => 'required|string',
        'pool_id'    => 'required|string',
        'assigned_to'=> 'required',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate assignment_type
    $validTypes = ['layer2', 'layer3'];
    $assignType = $body['assignment_type'] ?? 'layer2';
    if (!in_array($assignType, $validTypes, true)) {
        Response::unprocessable('Invalid assignment_type', ['assignment_type' => 'Must be layer2 or layer3']);
    }

    // Conflict check before assigning
    $checker = new ConflictChecker();
    if ($checker->checkConflict($body['ip_address'], $body['pool_id'])) {
        Response::unprocessable('IP conflict', ['ip_address' => 'This IP is already assigned or reserved']);
    }

    $allocator = new IPAllocator();
    $assignment = $allocator->assignSpecific(
        $body['ip_address'],
        $body['pool_id'],
        $body['assigned_to'],
        $assignType,
        $body['mac_address'] ?? ''
    );

    Response::json(['data' => $assignment], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\RuntimeException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create assignment: ' . $e->getMessage(), 500);
}
