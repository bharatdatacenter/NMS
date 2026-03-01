<?php

declare(strict_types=1);

/**
 * GET /api/clusters/{id}
 *
 * Get a single cluster by ID, including member list and last known status.
 *
 * Requires: nms.cluster.read permission
 */

use NMS\Core\Models\Devices\ClusterManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Cluster ID required', 400);
    }

    $manager = new ClusterManager();
    $cluster = $manager->findById($id);

    if (!$cluster) {
        Response::error('Cluster not found', 404);
    }

    Response::json(['data' => $cluster]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cluster ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve cluster: ' . $e->getMessage(), 500);
}
