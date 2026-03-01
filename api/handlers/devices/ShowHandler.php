<?php

declare(strict_types=1);

/**
 * GET /api/devices/{id}
 *
 * Get a single device by ID, including all ports and location data.
 */

use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Device ID required', 400);
    }

    $manager = new DeviceManager();
    $device  = $manager->findById($id);

    if (!$device) {
        Response::error('Device not found', 404);
    }

    Response::json(['data' => $device]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve device: ' . $e->getMessage(), 500);
}
