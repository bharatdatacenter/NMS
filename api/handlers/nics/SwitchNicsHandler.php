<?php

declare(strict_types=1);

/**
 * GET /api/nics/switch/{device_id}
 *
 * List all NICs connected to a specific switch/device.
 * Useful for switch port utilisation views.
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;

try {
    $deviceId = (string)($params['device_id'] ?? '');
    if ($deviceId === '') {
        Response::notFound('Device ID is required');
    }

    $manager = new NicManager();
    $nics    = $manager->findBySwitchDeviceId($deviceId);

    Response::json(['data' => $nics, 'total' => count($nics)]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to list switch NICs: ' . $e->getMessage(), 500);
}
