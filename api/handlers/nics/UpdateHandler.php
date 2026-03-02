<?php

declare(strict_types=1);

/**
 * PUT /api/nics/{id}
 *
 * Update NIC fields: status, vlan_id, vlan_name, access_mode, is_bond_member, bond_master.
 * Also accepts connected_to for port assignment update.
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;

try {
    $id = (string)($params['id'] ?? '');
    if ($id === '') {
        Response::notFound('NIC ID is required');
    }

    $manager = new NicManager();

    // Port assignment update
    if (!empty($body['connected_to'])) {
        $manager->updatePortAssignment($id, (array)$body['connected_to']);
    }

    // Field updates
    $updateFields = array_intersect_key($body, array_flip([
        'status', 'vlan_id', 'vlan_name', 'access_mode', 'is_bond_member', 'bond_master',
    ]));

    if (!empty($updateFields)) {
        $manager->update($id, $updateFields);
    }

    $updated = $manager->findById($id);
    if ($updated === null) {
        Response::notFound("NIC {$id} not found");
    }

    Response::json(['data' => $updated]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to update NIC: ' . $e->getMessage(), 500);
}
