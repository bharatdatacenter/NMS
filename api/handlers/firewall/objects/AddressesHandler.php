<?php

declare(strict_types=1);

/**
 * GET  /api/firewall/addresses  — list address objects
 * POST /api/firewall/addresses  — create address object
 *
 * Routing is method-based in this handler.
 */

use NMS\Core\Models\Firewall\ObjectManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $manager = new ObjectManager();

    if ($method === 'GET') {
        $result = $manager->listAddresses(
            [
                'device_id'  => $query['device_id']  ?? null,
                'ip_version' => $query['ip_version'] ?? null,
                'type'       => $query['type']       ?? null,
            ],
            (int) ($query['page']     ?? 1),
            (int) ($query['per_page'] ?? 100)
        );
        Response::json($result);

    } elseif ($method === 'POST') {
        $v = new Validator();
        $v->validate($body, [
            'name'       => 'required|string',
            'ip_version' => 'required|string',
        ]);
        if ($v->fails()) {
            Response::unprocessable('Validation failed', $v->getErrors());
        }

        $id = $manager->createAddress($body);
        Response::json(['data' => ['id' => $id]], 201);
    }

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Address object error: ' . $e->getMessage(), 500);
}
