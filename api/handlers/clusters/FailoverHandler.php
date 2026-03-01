<?php

declare(strict_types=1);

/**
 * POST /api/clusters/{id}/failover
 *
 * Trigger a manual failover event for a cluster.
 * Records the failover event in cluster_events and increments the cluster's failover counter.
 *
 * Body (optional):
 *   {
 *     "from_device_id": "...",
 *     "to_device_id":   "...",
 *     "reason":         "Planned maintenance"
 *   }
 *
 * NOTE: This endpoint records the event and updates the cluster state.
 * Actual device-level HA failover must be triggered manually on the device —
 * NMS does not push failover commands (read/monitor only for safety).
 *
 * Requires: nms.cluster.write permission
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

    $event = [
        'type'           => 'manual',
        'from_device_id' => $body['from_device_id'] ?? null,
        'to_device_id'   => $body['to_device_id']   ?? null,
        'reason'         => $body['reason']          ?? 'Manual failover via API',
        'initiated_by'   => $request['user']->sub    ?? 'api',
    ];

    $eventId = $manager->recordFailover($id, $event);

    // Re-fetch updated cluster
    $cluster = $manager->findById($id);

    Response::json([
        'data' => [
            'event_id'       => $eventId,
            'cluster_id'     => $id,
            'failover_count' => $cluster['failover_count'] ?? 0,
            'last_failover'  => $cluster['last_failover']  ?? null,
            'message'        => 'Failover event recorded. Trigger the actual device failover manually.',
        ],
    ], 201);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cluster ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to record failover: ' . $e->getMessage(), 500);
}
