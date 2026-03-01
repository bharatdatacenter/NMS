<?php

declare(strict_types=1);

/**
 * POST /api/cables
 *
 * Register a new cable between two device ports.
 *
 * Required: cable_id, endpoint_a (device_id + port_name), endpoint_b (device_id + port_name)
 * Optional: cable_type, length_meters, color, connector_a, connector_b,
 *           status, installed_at, tested, test_result, notes
 *
 * Validates:
 *  - Both device endpoints exist
 *  - Neither port is already connected to another cable
 *  - Triggers path materialization after creation
 */

use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'cable_id' => 'required|string',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    if (empty($body['endpoint_a']['device_id']) || empty($body['endpoint_a']['port_name'])) {
        Response::unprocessable('Validation failed', ['endpoint_a' => 'device_id and port_name required']);
    }
    if (empty($body['endpoint_b']['device_id']) || empty($body['endpoint_b']['port_name'])) {
        Response::unprocessable('Validation failed', ['endpoint_b' => 'device_id and port_name required']);
    }

    $manager = new CableManager();
    $id      = $manager->create($body, $request['user']->sub ?? 'system');
    $cable   = $manager->findById($id);

    Response::json(['data' => $cable], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage(), []);
} catch (\Exception $e) {
    Response::error('Failed to register cable: ' . $e->getMessage(), 500);
}
