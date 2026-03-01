<?php

declare(strict_types=1);

/**
 * DELETE /api/devices/{id}
 *
 * Delete a device. Removes it from any rack's installed_devices list.
 * Rejects deletion if cables are still connected.
 */

use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;
use MongoDB\BSON\ObjectId;

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

    // Reject if cables are still connected
    $deviceOid = new ObjectId($id);
    $cableCount = MongoDB::getInstance()
        ->selectCollection('cables')
        ->countDocuments([
            '$or' => [
                ['endpoint_a.device_id' => $deviceOid],
                ['endpoint_b.device_id' => $deviceOid],
            ],
        ]);

    if ($cableCount > 0) {
        Response::error(
            "Cannot delete device: $cableCount cable(s) still connected. Disconnect cables first.",
            409
        );
    }

    // Remove from rack(s)
    $manager->removeFromRack($id);

    $deleted = $manager->deleteById($id);

    Response::json(['deleted' => $deleted, 'id' => $id]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to delete device: ' . $e->getMessage(), 500);
}
