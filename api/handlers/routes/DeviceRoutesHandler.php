<?php

declare(strict_types=1);

/**
 * GET /api/routes/device/{device_id}
 *
 * List all static routes for a specific device.
 */

use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Helpers\Response;

try {
    $deviceId = $params['device_id'] ?? '';
    $manager  = new RouteManager();
    $result   = $manager->listByDevice(
        $deviceId,
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 100)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list device routes: ' . $e->getMessage(), 500);
}
