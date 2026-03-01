<?php

declare(strict_types=1);

/**
 * GET /api/routing/ospf/neighbors
 *
 * List stored OSPF neighbor states.
 * Optional query param: device_id
 */

use NMS\Core\Models\Routing\BGPMonitor;
use NMS\Core\Helpers\Response;

try {
    $monitor   = new BGPMonitor();
    $neighbors = $monitor->listOSPFNeighbors([
        'device_id' => $query['device_id'] ?? null,
    ]);

    Response::json(['data' => $neighbors]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list OSPF neighbors: ' . $e->getMessage(), 500);
}
