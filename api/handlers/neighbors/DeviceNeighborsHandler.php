<?php

declare(strict_types=1);

/**
 * GET /api/neighbors/device/{device_id}
 *
 * List all neighbor entries for a specific device.
 */

use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Helpers\Response;

try {
    $deviceId = $params['device_id'] ?? '';
    $manager  = new NeighborManager();
    $entries  = $manager->listByDevice($deviceId);

    Response::json(['data' => $entries]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list device neighbors: ' . $e->getMessage(), 500);
}
