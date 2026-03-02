<?php

declare(strict_types=1);

/**
 * GET /api/nics
 *
 * List NICs with optional filters.
 * Query params: ims_server_id, device_id, vlan_id, status, page, per_page
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;

try {
    $page    = max(1, (int)($query['page'] ?? 1));
    $perPage = min(100, max(1, (int)($query['per_page'] ?? 50)));

    $filters = array_filter([
        'ims_server_id' => $query['ims_server_id'] ?? null,
        'device_id'     => $query['device_id'] ?? null,
        'vlan_id'       => isset($query['vlan_id']) ? (int)$query['vlan_id'] : null,
        'status'        => $query['status'] ?? null,
    ], fn($v) => $v !== null && $v !== '');

    $manager = new NicManager();
    $result  = $manager->list($filters, $page, $perPage);

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list NICs: ' . $e->getMessage(), 500);
}
