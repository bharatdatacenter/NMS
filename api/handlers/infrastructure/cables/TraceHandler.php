<?php

declare(strict_types=1);

/**
 * GET /api/cables/trace/{device_id}/{port}
 *
 * Trace the complete cable path from a device port (through patch panels).
 * Returns pre-computed path from connectivity_paths or recomputes via graph walk.
 *
 * Response includes hop-by-hop traversal through all intermediate devices.
 */

use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Helpers\Response;

try {
    $deviceId = $params['device_id'] ?? '';
    $port     = $params['port'] ?? '';

    if (empty($deviceId) || empty($port)) {
        Response::error('device_id and port are required', 400);
    }

    // Validate device exists
    $deviceManager = new DeviceManager();
    $device = $deviceManager->findById($deviceId);
    if (!$device) {
        Response::error('Device not found', 404);
    }

    $pm   = new PathMaterializer();
    $path = $pm->getPath($deviceId, $port);

    if (empty($path)) {
        Response::json([
            'device_id'   => $deviceId,
            'port'        => $port,
            'device_name' => $device['name'],
            'connected'   => false,
            'message'     => 'No cable connected to this port or path not yet computed',
        ]);
    }

    Response::json([
        'connected'     => true,
        'data'          => $path,
        'hop_count'     => $path['hop_count'] ?? 0,
        'source'        => $path['source'] ?? null,
        'destination'   => $path['destination'] ?? null,
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid input: ' . $e->getMessage(), 400);
} catch (\Exception $e) {
    Response::error('Failed to trace cable path: ' . $e->getMessage(), 500);
}
