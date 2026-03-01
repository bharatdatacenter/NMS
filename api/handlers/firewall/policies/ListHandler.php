<?php

declare(strict_types=1);

/**
 * GET /api/firewall/policies
 *
 * Query params: ip_version, device_id, cluster_id, direction, action, page, per_page
 */

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PolicyManager();
    $result  = $manager->list(
        [
            'ip_version' => $query['ip_version'] ?? null,
            'device_id'  => $query['device_id']  ?? null,
            'cluster_id' => $query['cluster_id'] ?? null,
            'direction'  => $query['direction']  ?? null,
            'action'     => $query['action']     ?? null,
        ],
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 100)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list policies: ' . $e->getMessage(), 500);
}
