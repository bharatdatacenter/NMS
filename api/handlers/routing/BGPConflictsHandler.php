<?php

declare(strict_types=1);

/**
 * GET /api/routing/bgp/conflicts
 *
 * Check for BGP-learned routes that overlap a given CIDR.
 * Targeted query — does NOT dump full BGP table.
 *
 * Required query param: cidr (e.g. 85.209.161.100/32)
 * Optional query param: device_id (limit check to one device)
 */

use NMS\Core\Models\Ipam\ConflictChecker;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($query, [
        'cidr' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $cidr     = $query['cidr'];
    $deviceId = $query['device_id'] ?? null;

    $checker   = new ConflictChecker();
    $conflicts = $checker->checkBGPConflict($cidr, $deviceId);

    Response::json([
        'data' => [
            'cidr'      => $cidr,
            'conflicts' => $conflicts,
            'has_conflict' => !empty($conflicts),
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('BGP conflict check failed: ' . $e->getMessage(), 500);
}
