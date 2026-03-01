<?php

declare(strict_types=1);

/**
 * POST /api/devices
 *
 * Create a new device or patch panel.
 *
 * Required: name, vendor, role
 * Optional: hostname, ip_address, model, serial_number, firmware_version,
 *           site_id, rack_id, rack_unit, rack_side, ports, tags, notes, status
 *
 * Patch panel: set role="patch_panel", vendor="generic"
 */

use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $data = $v->validate($body, [
        'name'   => 'required|string',
        'vendor' => 'required|string',
        'role'   => 'required|string',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $validRoles = [
        'edge_firewall', 'core_router', 'distribution_switch',
        'access_switch', 'vpn_gateway', 'load_balancer', 'patch_panel', 'server',
    ];
    if (!in_array($body['role'], $validRoles, true)) {
        Response::unprocessable('Invalid role', ['role' => 'Must be one of: ' . implode(', ', $validRoles)]);
    }

    $manager = new DeviceManager();
    $id = $manager->create($body, $request['user']->sub ?? 'system');

    $device = $manager->findById($id);
    Response::json(['data' => $device], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create device: ' . $e->getMessage(), 500);
}
