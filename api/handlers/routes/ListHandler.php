<?php

declare(strict_types=1);

/**
 * GET /api/routes
 *
 * Query params: ip_version, device_id, cluster_id, route_type, page, per_page
 */

use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new RouteManager();
    $result  = $manager->list(
        [
            'ip_version' => $query['ip_version'] ?? null,
            'device_id'  => $query['device_id']  ?? null,
            'cluster_id' => $query['cluster_id'] ?? null,
            'route_type' => $query['route_type'] ?? null,
        ],
        (int) ($query['page']     ?? 1),
        (int) ($query['per_page'] ?? 50)
    );

    Response::json($result);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list routes: ' . $e->getMessage(), 500);
}
