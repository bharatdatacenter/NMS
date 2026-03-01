<?php

declare(strict_types=1);

namespace NMS\Core\Models\Routing;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * RouteSync
 *
 * Pushes static routes to devices via vendor adapters.
 * Cluster-aware: if the route's device is in an HA cluster, pushes to
 * cluster.management_ip rather than the individual device's ip_address.
 */
class RouteSync
{
    private RouteManager $routeManager;
    private DeviceManager $deviceManager;
    private \MongoDB\Collection $clusters;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->routeManager  = new RouteManager();
        $this->deviceManager = new DeviceManager();
        $this->clusters      = $db->selectCollection('device_clusters');
    }

    /**
     * Push a static route to its device (or cluster management IP).
     *
     * @param  string $routeId  MongoDB ObjectId string of the route document
     * @return bool             True on success
     * @throws \RuntimeException on device/adapter errors
     */
    public function syncToDevice(string $routeId): bool
    {
        $route = $this->routeManager->findById($routeId);
        if (!$route) {
            throw new \RuntimeException("Route {$routeId} not found");
        }

        $device = $this->deviceManager->findById((string) $route['device_id']);
        if (!$device) {
            throw new \RuntimeException("Device not found for route {$routeId}");
        }

        $device  = $this->resolveClusterDevice($device, $route);
        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $ok = $adapter->addStaticRoute(
                $route['destination'],
                $route['gateway'] ?? '',
                $route['interface_name'] ?? null
            );
        } finally {
            $adapter->disconnect();
        }

        $this->routeManager->updateSyncStatus(
            $routeId,
            $ok,
            null,
            $ok ? null : 'Adapter returned false'
        );

        return $ok;
    }

    /**
     * Remove a route from the device (called before deleting from DB).
     */
    public function removeFromDevice(string $routeId): bool
    {
        $route = $this->routeManager->findById($routeId);
        if (!$route) {
            return false;
        }

        $device = $this->deviceManager->findById((string) $route['device_id']);
        if (!$device) {
            return false;
        }

        $device  = $this->resolveClusterDevice($device, $route);
        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            return false;
        }

        $adapter->connect();
        try {
            $ok = $adapter->removeRoute($route['destination']);
        } finally {
            $adapter->disconnect();
        }

        return $ok;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * Replace device ip_address with cluster management_ip when clustered.
     */
    private function resolveClusterDevice(array $device, array $route): array
    {
        $clusterId = $route['cluster_id'] ?? $device['cluster_id'] ?? null;
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
