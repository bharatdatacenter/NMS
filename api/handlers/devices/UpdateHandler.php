<?php

declare(strict_types=1);

/**
 * PUT /api/devices/{id}
 *
 * Update device metadata. Does not trigger vendor connection (Phase 3).
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

    if (empty($body)) {
        Response::error('No fields to update', 400);
    }

    $updated = $manager->update($id, $body);

    $device = $manager->findById($id);
    Response::json(['data' => $device, 'updated' => $updated]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid input: ' . $e->getMessage(), 400);
} catch (\Exception $e) {
    Response::error('Failed to update device: ' . $e->getMessage(), 500);
}
