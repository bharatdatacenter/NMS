<?php

declare(strict_types=1);

/**
 * GET /api/firewall/vips
 *
 * List firewall VIPs (IPv4 only — VIPs are never created for IPv6).
 * Query params: device_id, cluster_id, page, per_page
 */

use NMS\Core\Models\Firewall\ObjectManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new ObjectManager();
    $result  = $manager->listVips(
        [
            'device_id'  => $query['device_id']  ?? null,
            'cluster_id' => $query['cluster_id'] ?? null,
        ],
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 100)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list VIPs: ' . $e->getMessage(), 500);
}
