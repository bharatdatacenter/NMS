<?php

declare(strict_types=1);

namespace NMS\Core\Models\Routing;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * BGPMonitor
 *
 * Read-only polling of BGP sessions and OSPF neighbors from devices.
 * Never writes config to devices — only upserts polled state into MongoDB.
 *
 * bgp_sessions is the source of truth for dashboard visibility and
 * pre-provisioning conflict detection (ConflictChecker::checkBGPConflict).
 */
class BGPMonitor
{
    private \MongoDB\Collection $bgpSessions;
    private \MongoDB\Collection $ospfNeighbors;
    private DeviceManager $deviceManager;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->bgpSessions   = $db->selectCollection('bgp_sessions');
        $this->ospfNeighbors = $db->selectCollection('ospf_neighbors');
        $this->deviceManager = new DeviceManager();
    }

    /**
     * Poll BGP sessions from a device and upsert into bgp_sessions.
     */
    public function pollSessions(string $deviceId): void
    {
        $device = $this->deviceManager->findById($deviceId);
        if (!$device) {
            throw new \RuntimeException("Device {$deviceId} not found");
        }

        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $sessions = $adapter->getBGPSessions();
        } finally {
            $adapter->disconnect();
        }

        $now       = new UTCDateTime();
        $clusterId = isset($device['cluster_id']) ? new ObjectId((string) $device['cluster_id']) : null;

        foreach ($sessions as $s) {
            $this->bgpSessions->updateOne(
                [
                    'device_id'   => new ObjectId($deviceId),
                    'neighbor_ip' => $s['neighbor_ip'] ?? '',
                ],
                [
                    '$set' => array_merge($s, [
                        'device_id'   => new ObjectId($deviceId),
                        'cluster_id'  => $clusterId,
                        'last_polled' => $now,
                    ]),
                    '$setOnInsert' => ['created_at' => $now],
                ],
                ['upsert' => true]
            );
        }
    }

    /**
     * Poll OSPF neighbors from a device and upsert into ospf_neighbors.
     */
    public function pollOSPF(string $deviceId): void
    {
        $device = $this->deviceManager->findById($deviceId);
        if (!$device) {
            throw new \RuntimeException("Device {$deviceId} not found");
        }

        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $neighbors = $adapter->getOSPFNeighbors();
        } finally {
            $adapter->disconnect();
        }

        $now       = new UTCDateTime();
        $clusterId = isset($device['cluster_id']) ? new ObjectId((string) $device['cluster_id']) : null;

        foreach ($neighbors as $n) {
            $this->ospfNeighbors->updateOne(
                [
                    'device_id'   => new ObjectId($deviceId),
                    'neighbor_id' => $n['neighbor_id'] ?? '',
                ],
                [
                    '$set' => array_merge($n, [
                        'device_id'   => new ObjectId($deviceId),
                        'cluster_id'  => $clusterId,
                        'last_polled' => $now,
                    ]),
                    '$setOnInsert' => ['created_at' => $now],
                ],
                ['upsert' => true]
            );
        }
    }

    // ─── Query ────────────────────────────────────────────────────────────────

    public function listSessions(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = [];
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['state'])) {
            $filter['state'] = $filters['state'];
        }

        $total  = $this->bgpSessions->countDocuments($filter);
        $cursor = $this->bgpSessions->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['device_id' => 1, 'neighbor_ip' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function getDeviceSessions(string $deviceId): array
    {
        $cursor = $this->bgpSessions->find(
            ['device_id' => new ObjectId($deviceId)],
            ['sort' => ['neighbor_ip' => 1]]
        );
        return iterator_to_array($cursor, false);
    }

    public function listOSPFNeighbors(array $filters = []): array
    {
        $filter = [];
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        $cursor = $this->ospfNeighbors->find($filter, ['sort' => ['device_id' => 1]]);
        return iterator_to_array($cursor, false);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function buildSecretsManager(): \NMS\Core\Models\Secrets\SecretsManagerInterface
    {
        $config = require dirname(__DIR__, 3) . '/core/config/vault.php';
        if (!empty($config['enabled'])) {
            return new VaultSecretsManager($config);
        }
        return new AppEncryptedSecretsManager();
    }
}
