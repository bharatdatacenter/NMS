<?php

declare(strict_types=1);

/**
 * PUT /api/clusters/{id}
 *
 * Update cluster metadata: name, management_ip, type, notes, tags.
 * Does NOT modify members — membership changes are handled separately.
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

    // Validate management_ip if provided
    if (isset($body['management_ip']) && !filter_var($body['management_ip'], FILTER_VALIDATE_IP)) {
        Response::unprocessable('Validation failed', ['management_ip' => 'Must be a valid IP address']);
    }

    $validTypes = ['ha_pair', 'stack', 'vrrp'];
    if (isset($body['type']) && !in_array($body['type'], $validTypes, true)) {
        Response::unprocessable('Validation failed', ['type' => 'Must be: ' . implode(', ', $validTypes)]);
    }

    $updated = $manager->update($id, $body);

    if (!$updated) {
        Response::json(['data' => $cluster]);  // No changes — return current
    }

    $cluster = $manager->findById($id);
    Response::json(['data' => $cluster]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cluster ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to update cluster: ' . $e->getMessage(), 500);
}
