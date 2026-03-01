<?php

declare(strict_types=1);

/**
 * POST /api/firewall/policies
 *
 * Create a firewall policy. ip_version and device_id are required.
 * IPv6 policies: nat_enabled is forced to false, vip_id to null.
 */

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'ip_version' => 'required|string',
        'device_id'  => 'required|string',
        'name'       => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validVersions = ['ipv4', 'ipv6'];
    if (!in_array($body['ip_version'], $validVersions, true)) {
        Response::unprocessable('Invalid ip_version', ['ip_version' => 'Must be ipv4 or ipv6']);
    }

    $manager = new PolicyManager();
    $id      = $manager->create($body, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['id' => $id]], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create policy: ' . $e->getMessage(), 500);
}
