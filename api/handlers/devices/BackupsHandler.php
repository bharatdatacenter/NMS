<?php

declare(strict_types=1);

/**
 * GET /api/devices/{id}/backups
 *
 * List config backups for a device, newest first.
 * Does NOT return backup content — use /api/devices/{id}/backups/{backup_id} for content.
 *
 * Requires: nms.device.read permission
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

    $limit   = min((int)($query['limit'] ?? 20), 100);
    $backups = $manager->listBackups($id, $limit);

    Response::json([
        'data'  => $backups,
        'total' => count($backups),
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve backups: ' . $e->getMessage(), 500);
}
