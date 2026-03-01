<?php

declare(strict_types=1);

namespace NMS\Core\Models\Neighbors;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * NeighborSync
 *
 * Pushes static ARP/NDP entries to devices via vendor adapters.
 * Cluster-aware: uses cluster management_ip when device is in an HA cluster.
 */
class NeighborSync
{
    private NeighborManager $neighborManager;
    private DeviceManager $deviceManager;
    private \MongoDB\Collection $clusters;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->neighborManager = new NeighborManager();
        $this->deviceManager   = new DeviceManager();
        $this->clusters        = $db->selectCollection('device_clusters');
    }

    /**
     * Push a neighbor entry to the device.
     *
     * @param  string $entryId  MongoDB ObjectId string of the neighbor_entries document
     * @return bool
     * @throws \RuntimeException on device/adapter errors
     */
    public function syncToDevice(string $entryId): bool
    {
        $entry = $this->neighborManager->findById($entryId);
        if (!$entry) {
            throw new \RuntimeException("Neighbor entry {$entryId} not found");
        }

        $device = $this->deviceManager->findById((string) $entry['device_id']);
        if (!$device) {
            throw new \RuntimeException("Device not found for entry {$entryId}");
        }

        $device  = $this->resolveClusterDevice($device, $entry);
        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $ok = $adapter->addStaticNeighbor(
                $entry['ip_address'],
                $entry['mac_address'],
                $entry['interface_name'] ?? ''
            );
        } finally {
            $adapter->disconnect();
        }

        $this->neighborManager->updateSyncStatus(
            $entryId,
            $ok,
            null,
            $ok ? null : 'Adapter returned false'
        );

        return $ok;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function resolveClusterDevice(array $device, array $entry): array
    {
        $clusterId = $entry['cluster_id'] ?? $device['cluster_id'] ?? null;
        if (!$clusterId) {
            return $device;
        }

        $cluster = $this->clusters->findOne(['_id' => new ObjectId((string) $clusterId)]);
        if ($cluster && !empty($cluster['management_ip'])) {
            $device['ip_address'] = $cluster['management_ip'];
        }

        return $device;
    }

    private function buildSecretsManager(): \NMS\Core\Models\Secrets\SecretsManagerInterface
    {
        $config = require dirname(__DIR__, 3) . '/core/config/vault.php';
        if (!empty($config['enabled'])) {
            return new VaultSecretsManager($config);
        }
        return new AppEncryptedSecretsManager();
    }
}
