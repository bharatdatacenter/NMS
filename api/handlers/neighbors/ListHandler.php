<?php

declare(strict_types=1);

/**
 * GET /api/neighbors
 *
 * Query params: protocol (arp|ndp), device_id, ip_version, page, per_page
 */

use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new NeighborManager();
    $result  = $manager->list(
        [
            'protocol'   => $query['protocol']   ?? null,
            'device_id'  => $query['device_id']  ?? null,
            'ip_version' => $query['ip_version'] ?? null,
        ],
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 100)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list neighbors: ' . $e->getMessage(), 500);
}
