<?php

declare(strict_types=1);

/**
 * GET /api/devices/{id}/ports
 *
 * List all ports defined on a device, including connection info.
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

    $ports = $device['ports'] ?? [];

    Response::json([
        'device_id'   => $id,
        'device_name' => $device['name'],
        'port_count'  => count($ports),
        'data'        => $ports,
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve ports: ' . $e->getMessage(), 500);
}
