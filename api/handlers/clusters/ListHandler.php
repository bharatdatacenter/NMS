<?php

declare(strict_types=1);

/**
 * GET /api/clusters
 *
 * List clusters with optional filters: vendor, type, site_id, status
 *
 * Requires: nms.cluster.read permission
 */

use NMS\Core\Models\Devices\ClusterManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new ClusterManager();

    $filters = array_intersect_key(
        $query ?? [],
        array_flip(['vendor', 'type', 'site_id', 'status'])
    );

    $page    = max(1, (int)($query['page']     ?? 1));
    $perPage = min(100, max(1, (int)($query['per_page'] ?? 50)));

    $result = $manager->list($filters, $page, $perPage);

    Response::json($result);

} catch (\Exception $e) {
    Response::error('Failed to list clusters: ' . $e->getMessage(), 500);
}
