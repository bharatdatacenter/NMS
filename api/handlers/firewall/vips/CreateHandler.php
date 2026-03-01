<?php

declare(strict_types=1);

/**
 * POST /api/firewall/vips
 *
 * Create a VIP (DNAT mapping). Always IPv4 — never created for IPv6.
 *
 * Required: device_id, external_ip, mapped_ip
 * Optional: cluster_id, name, external_port, mapped_port, protocol, comment
 */

use NMS\Core\Models\Firewall\ObjectManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'device_id'   => 'required|string',
        'external_ip' => 'required|string',
        'mapped_ip'   => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $manager = new ObjectManager();
    $id      = $manager->createVip($body);

    Response::json(['data' => ['id' => $id]], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create VIP: ' . $e->getMessage(), 500);
}
