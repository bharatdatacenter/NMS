<?php

declare(strict_types=1);

/**
 * POST /api/neighbors
 *
 * Create a static ARP (IPv4) or NDP (IPv6) neighbor entry.
 *
 * Required: protocol (arp|ndp), device_id, ip_address, mac_address
 * Optional: cluster_id, interface_name, entry_type, ip_assignment_id, owner, notes
 */

use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'protocol'    => 'required|string',
        'device_id'   => 'required|string',
        'ip_address'  => 'required|string',
        'mac_address' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validProtocols = ['arp', 'ndp'];
    if (!in_array($body['protocol'], $validProtocols, true)) {
        Response::unprocessable('Invalid protocol', ['protocol' => "Must be 'arp' or 'ndp'"]);
    }

    $manager = new NeighborManager();
    $id      = $manager->create($body, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['id' => $id]], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create neighbor entry: ' . $e->getMessage(), 500);
}
