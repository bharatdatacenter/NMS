<?php

declare(strict_types=1);

/**
 * GET /api/cables/device/{device_id}
 *
 * Get all cables connected to a specific device (both endpoints).
 */

use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Helpers\Response;

try {
    $deviceId = $params['device_id'] ?? '';
    if (empty($deviceId)) {
        Response::error('Device ID required', 400);
    }

    // Validate device exists
    $deviceManager = new DeviceManager();
    $device = $deviceManager->findById($deviceId);
    if (!$device) {
        Response::error('Device not found', 404);
    }

    $manager = new CableManager();
    $cables  = $manager->listForDevice($deviceId);

    Response::json([
        'device_id'   => $deviceId,
        'device_name' => $device['name'],
        'count'       => count($cables),
        'data'        => $cables,
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve device cables: ' . $e->getMessage(), 500);
}
