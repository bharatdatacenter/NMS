<?php

declare(strict_types=1);

/**
 * GET /api/devices/{id}/status
 *
 * Connect to a device via its vendor adapter and return live status:
 * interfaces, system info, and circuit breaker state.
 *
 * Requires: nms.device.read permission
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

    // Check circuit breaker state from stored document (fast path)
    $cbState = $device['circuit_breaker']['state'] ?? 'closed';

    // Build adapter
    $secrets = new VaultSecretsManager();
    $adapter = DeviceFactory::create($device, $secrets);

    if ($adapter === null) {
        Response::json([
            'data' => [
                'device_id'       => $id,
                'vendor'          => $device['vendor'],
                'supported'       => false,
                'circuit_breaker' => $device['circuit_breaker'] ?? null,
                'message'         => "Vendor '{$device['vendor']}' has no adapter in Phase 3. Supported: "
                    . implode(', ', DeviceFactory::supportedVendors()),
            ],
        ]);
    }

    // Try connecting (goes through CircuitBreaker + RetryHandler)
    try {
        $connected  = $adapter->connect();
        $systemInfo = $adapter->getSystemInfo();
        $interfaces = $adapter->getInterfaces();
        $haStatus   = $adapter->getHAStatus();

        Response::json([
            'data' => [
                'device_id'       => $id,
                'vendor'          => $device['vendor'],
                'reachable'       => $connected,
                'system_info'     => $systemInfo,
                'interfaces'      => $interfaces,
                'ha_status'       => $haStatus,
                'circuit_breaker' => $device['circuit_breaker'] ?? null,
                'polled_at'       => date('c'),
            ],
        ]);

    } catch (CircuitOpenException $e) {
        Response::json([
            'data' => [
                'device_id'       => $id,
                'vendor'          => $device['vendor'],
                'reachable'       => false,
                'circuit_breaker' => $device['circuit_breaker'] ?? null,
                'error'           => 'Circuit breaker is open — device is unreachable',
                'polled_at'       => date('c'),
            ],
        ], 503);
    }

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve device status: ' . $e->getMessage(), 500);
}
