<?php

declare(strict_types=1);

/**
 * POST /api/routes
 *
 * Required: ip_version, destination, device_id, gateway
 * Optional: cluster_id, interface_name, distance, metric, route_type, purpose, ip_assignment_id
 */

use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'ip_version'  => 'required|string',
        'destination' => 'required|string',
        'device_id'   => 'required|string',
        'gateway'     => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validVersions = ['ipv4', 'ipv6'];
    if (!in_array($body['ip_version'], $validVersions, true)) {
        Response::unprocessable('Invalid ip_version', ['ip_version' => 'Must be ipv4 or ipv6']);
    }

    $manager = new RouteManager();
    $id      = $manager->create($body, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['id' => $id]], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create route: ' . $e->getMessage(), 500);
}
