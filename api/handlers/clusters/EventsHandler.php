<?php

declare(strict_types=1);

/**
 * GET /api/clusters/{id}/events
 *
 * List failover and status events for a cluster, newest first.
 *
 * Query params: limit (default 50, max 200)
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

    $limit  = min(200, max(1, (int)($query['limit'] ?? 50)));
    $events = $manager->getEvents($id, $limit);

    Response::json([
        'data'  => $events,
        'total' => count($events),
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cluster ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve cluster events: ' . $e->getMessage(), 500);
}
