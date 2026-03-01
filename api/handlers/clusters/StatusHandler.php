<?php

declare(strict_types=1);

/**
 * GET /api/clusters/{id}/status
 *
 * Poll each cluster member device via its vendor adapter and return live status.
 * Updates the cluster's member statuses in MongoDB.
 *
 * Requires: nms.cluster.read permission
 */

use NMS\Core\Models\Devices\ClusterManager;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Helpers\CircuitOpenException;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Cluster ID required', 400);
    }

    $clusterManager = new ClusterManager();
    $deviceManager  = new DeviceManager();
    $cluster        = $clusterManager->findById($id);

    if (!$cluster) {
        Response::error('Cluster not found', 404);
    }

    $secrets       = new VaultSecretsManager();
    $memberResults = [];

    foreach ($cluster['members'] ?? [] as $member) {
        $deviceId = $member['device_id']['$oid'] ?? (string)($member['device_id'] ?? '');
        if (empty($deviceId)) {
            continue;
        }

        $device  = $deviceManager->findById($deviceId);
        $adapter = $device ? DeviceFactory::create($device, $secrets) : null;

        if ($adapter === null) {
            $memberResults[] = [
                'device_id' => $deviceId,
                'node_ip'   => $member['node_ip'] ?? null,
                'role'      => $member['role'] ?? null,
                'status'    => 'unsupported',
                'details'   => null,
            ];
            continue;
        }

        try {
            $adapter->connect();
            $haStatus = $adapter->getHAStatus();
            $sysInfo  = $adapter->getSystemInfo();

            $memberResults[] = [
                'device_id' => $deviceId,
                'node_ip'   => $member['node_ip'] ?? null,
                'role'      => $member['role'] ?? null,
                'status'    => 'online',
                'details'   => [
                    'ha_status'   => $haStatus,
                    'system_info' => $sysInfo,
                ],
            ];
        } catch (CircuitOpenException) {
            $memberResults[] = [
                'device_id' => $deviceId,
                'node_ip'   => $member['node_ip'] ?? null,
                'role'      => $member['role'] ?? null,
                'status'    => 'unreachable',
                'details'   => ['error' => 'Circuit breaker open'],
            ];
        } catch (\Exception $e) {
            $memberResults[] = [
                'device_id' => $deviceId,
                'node_ip'   => $member['node_ip'] ?? null,
                'role'      => $member['role'] ?? null,
                'status'    => 'error',
                'details'   => ['error' => $e->getMessage()],
            ];
        }
    }

    // Persist polled statuses
    $clusterManager->updateMemberStatuses($id, $memberResults);

    // Re-fetch updated cluster
    $cluster = $clusterManager->findById($id);

    Response::json([
        'data' => [
            'cluster_id' => $id,
            'status'     => $cluster['status'] ?? 'unknown',
            'members'    => $memberResults,
            'polled_at'  => date('c'),
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cluster ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to get cluster status: ' . $e->getMessage(), 500);
}
