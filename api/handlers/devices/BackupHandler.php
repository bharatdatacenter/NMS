<?php

declare(strict_types=1);

/**
 * POST /api/devices/{id}/backup
 *
 * Trigger a config backup for a device.
 * Connects via vendor adapter, fetches config, stores in device_backups collection.
 *
 * Requires: nms.device.write permission
 */

use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Helpers\CircuitOpenException;
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

    $secrets = new VaultSecretsManager();
    $adapter = DeviceFactory::create($device, $secrets);

    if ($adapter === null) {
        Response::error("No adapter available for vendor '{$device['vendor']}'", 422);
    }

    try {
        $adapter->connect();
        $configContent = $adapter->backupConfig();
    } catch (CircuitOpenException $e) {
        Response::error('Device is unreachable (circuit breaker open). Cannot back up.', 503);
    }

    // Store the backup
    $backupId = $manager->storeBackup($id, $configContent);

    Response::json([
        'data' => [
            'backup_id'  => $backupId,
            'device_id'  => $id,
            'size_bytes' => strlen($configContent),
            'created_at' => date('c'),
        ],
    ], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Backup failed: ' . $e->getMessage(), 500);
}
