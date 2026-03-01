<?php

declare(strict_types=1);

/**
 * POST /api/devices/{id}/execute
 *
 * Execute an allowlisted read-only command on a device via its vendor adapter.
 * Commands are enforced against the vendor's static allowlist — never the full CLI.
 *
 * Body: {"command": "ping"}
 *
 * Returns 422 if the command is not in the vendor's allowlist.
 * Requires: nms.device.execute permission
 */

use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Helpers\CircuitOpenException;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Device ID required', 400);
    }

    $v = new Validator();
    $v->validate($body, ['command' => 'required|string']);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $command = trim($body['command'] ?? '');
    if (empty($command)) {
        Response::unprocessable('Validation failed', ['command' => 'Command is required']);
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

    // Validate against allowlist BEFORE connecting (fail fast)
    $allowed = $adapter->getAllowedCommands();
    if (!in_array($command, $allowed, true)) {
        Response::unprocessable(
            'Command not permitted',
            ['command' => "Not in allowlist. Allowed commands: " . implode(', ', $allowed)]
        );
    }

    try {
        $adapter->connect();
        $output = $adapter->executeCommand($command);
    } catch (CircuitOpenException $e) {
        Response::error('Device is unreachable (circuit breaker open). Cannot execute command.', 503);
    } catch (\InvalidArgumentException $e) {
        // Adapter double-checks — surface as 422
        Response::unprocessable('Command not permitted', ['command' => $e->getMessage()]);
    }

    Response::json([
        'data' => [
            'device_id'   => $id,
            'vendor'      => $device['vendor'],
            'command'     => $command,
            'output'      => $output,
            'executed_at' => date('c'),
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400);
} catch (\Exception $e) {
    Response::error('Command execution failed: ' . $e->getMessage(), 500);
}
