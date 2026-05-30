<?php

declare(strict_types=1);

/**
 * POST /api/integration/ims/validate-availability
 *
 * Read-only check: validates IPAM pool availability and device reachability.
 * Returns immediately — no mutations.
 *
 * Body: {
 *   pool_id?, ip_version?, device_id?, cluster_id?
 * }
 */

use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $db     = MongoDB::getInstance();
    $errors = [];

    // Check pool availability if pool_id provided
    if (!empty($body['pool_id'])) {
        $pool = $db->selectCollection('ip_pools')->findOne([
            '_id' => new \MongoDB\BSON\ObjectId($body['pool_id']),
        ]);
        if (!$pool) {
            $errors[] = 'Pool not found: ' . $body['pool_id'];
        } else {
            $used  = (int)($pool['used_count'] ?? 0);
            $total = (int)($pool['total_addresses'] ?? 0);
            if ($used >= $total) {
                $errors[] = 'Pool exhausted: ' . ($pool['name'] ?? $body['pool_id']);
            }
        }
    }

    // Check device reachability if device_id provided
    if (!empty($body['device_id'])) {
        $device = $db->selectCollection('devices')->findOne([
            '_id' => new \MongoDB\BSON\ObjectId($body['device_id']),
        ]);
        if (!$device) {
            $errors[] = 'Device not found: ' . $body['device_id'];
        } elseif (($device['status'] ?? '') !== 'active') {
            $errors[] = 'Device not active: ' . ($device['name'] ?? $body['device_id']);
        }
    }

    $available = empty($errors);

    Response::json([
        'available' => $available,
        'checks'    => array_map(fn($v) => ['status' => 'failed', 'message' => $v], $errors)
                     + ($available ? ['all' => ['status' => 'passed']] : []),
        'errors'    => $errors,
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Availability check failed: ' . $e->getMessage(), 500);
}
