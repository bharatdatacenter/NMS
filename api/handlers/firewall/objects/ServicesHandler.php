<?php

declare(strict_types=1);

/**
 * GET  /api/firewall/services  — list service objects
 * POST /api/firewall/services  — create service object
 */

use NMS\Core\Models\Firewall\ObjectManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $manager = new ObjectManager();

    if ($method === 'GET') {
        $result = $manager->listServices(
            [
                'device_id' => $query['device_id'] ?? null,
                'protocol'  => $query['protocol']  ?? null,
            ],
            (int) ($query['page']     ?? 1),
            (int) ($query['per_page'] ?? 100)
        );
        Response::json($result);

    } elseif ($method === 'POST') {
        $v = new Validator();
        $v->validate($body, [
            'name' => 'required|string',
        ]);
        if ($v->fails()) {
            Response::unprocessable('Validation failed', $v->getErrors());
        }

        $id = $manager->createService($body);
        Response::json(['data' => ['id' => $id]], 201);
    }

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Service object error: ' . $e->getMessage(), 500);
}
