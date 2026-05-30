<?php

declare(strict_types=1);

/**
 * POST /api/devices/{id}/drift/scan
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Drift\DriftDetector;

try {
    $params = $request['params'] ?? [];

    $deviceId = (string)($params['id'] ?? '');
    if ($deviceId === '') {
        Response::error('Device ID required', 400);
    }

    $detector = new DriftDetector();
    $result = $detector->scanDevice($deviceId);

    Response::json([
        'data' => [
            'device_id' => $deviceId,
            'drift_detected' => $result !== null,
            'drift' => $result,
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400, ['message' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Drift scan failed: ' . $e->getMessage(), 500);
}
