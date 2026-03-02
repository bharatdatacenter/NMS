<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;

/**
 * NodeDiscovery: Discovers device interfaces, neighbors, and ports
 *
 * For each device:
 * 1. Call adapter->getInterfaces() to get port list
 * 2. Call adapter->getNeighborDiscovery() (CDP/LLDP/MikroTik neighbor) data
 * 3. Call adapter->getNeighborTable() (ARP/MAC table)
 * 4. Build/update device port list
 */
class NodeDiscovery
{
    private Collection $devicesCollection;
    private Collection $macAddressCollection;

    public function __construct()
    {
        $this->devicesCollection = new Collection('devices');
        $this->macAddressCollection = new Collection('mac_address_registry');
    }

    /**
     * Discover interfaces and neighbors for a device
     *
     * @param ObjectId|string $deviceId
     * @param object $adapter Device adapter instance (implements NetworkDeviceInterface)
     * @return array Discovered node data {interfaces, neighbors, status}
     * @throws \Exception
     */
    public function discoverNode($deviceId, $adapter): array
    {
        if (is_string($deviceId)) {
            $deviceId = new ObjectId($deviceId);
        }

        $device = $this->devicesCollection->findOne(['_id' => $deviceId]);
        if (!$device) {
            throw new \Exception("Device not found: {$deviceId}");
        }

        try {
            // 1. Discover interfaces
            $interfaces = $this->discoverInterfaces($adapter, $deviceId);

            // 2. Discover neighbors (CDP/LLDP/MikroTik neighbor)
            $neighbors = $this->discoverNeighbors($adapter, $deviceId, $device->name ?? 'unknown');

            // 3. Get neighbor table (ARP/NDP/MAC)
            $neighborTable = $this->getNeighborTable($adapter, $deviceId);

            // 4. Update device ports in database
            $this->updateDevicePorts($deviceId, $interfaces);

            // 5. Update device status and last_seen
            $this->devicesCollection->updateOne(
                ['_id' => $deviceId],
                [
                    '$set' => [
                        'status' => 'online',
                        'last_seen' => new \MongoDB\BSON\UTCDateTime(),
                        'port_count' => count($interfaces),
                    ]
                ]
            );

            return [
                'device_id' => $deviceId,
                'device_name' => $device->name ?? 'unknown',
                'status' => 'online',
                'interfaces' => $interfaces,
                'neighbors' => $neighbors,
                'neighbor_table' => $neighborTable,
                'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
        } catch (\Exception $e) {
            // Mark device as unreachable
            $this->devicesCollection->updateOne(
                ['_id' => $deviceId],
                [
                    '$set' => [
                        'status' => 'unreachable',
                        'last_seen' => new \MongoDB\BSON\UTCDateTime(),
                    ]
                ]
            );
            throw $e;
        }
    }

    /**
     * Discover interfaces from device via adapter
     *
     * @param object $adapter Device adapter
     * @param ObjectId $deviceId
     * @return array List of interfaces {name, ip, status, type, mtu, description}
     */
    private function discoverInterfaces($adapter, $deviceId): array
    {
        $adapterInterfaces = $adapter->getInterfaces();
        $interfaces = [];

        foreach ($adapterInterfaces as $iface) {
            // Store in device ports array
            $interfaces[] = [
                'name' => $iface['name'] ?? null,
                'ip' => $iface['ip'] ?? null,
                'ip_version' => $iface['ip_version'] ?? null,
                'status' => $iface['status'] ?? 'unknown',
                'type' => $iface['type'] ?? 'ethernet',
                'mtu' => $iface['mtu'] ?? 1500,
                'description' => $iface['description'] ?? null,
                'mac_address' => $iface['mac_address'] ?? null,
                'speed_mbps' => $iface['speed_mbps'] ?? null,
            ];
        }

        return $interfaces;
    }

    /**
     * Discover neighbors using CDP/LLDP/MikroTik neighbor discovery
     *
     * @param object $adapter Device adapter
     * @param ObjectId $deviceId
     * @param string $deviceName
     * @return array List of neighbors {local_port, neighbor_device, neighbor_port, protocol}
     */
    private function discoverNeighbors($adapter, $deviceId, string $deviceName): array
    {
        try {
            $neighborData = $adapter->getNeighborDiscovery();
        } catch (\Exception $e) {
            // Neighbor discovery may not be supported on all devices
            return [];
        }

        $neighbors = [];
        foreach ($neighborData as $neighbor) {
            $neighbors[] = [
                'local_port' => $neighbor['local_port'] ?? null,
                'neighbor_device' => $neighbor['neighbor_device'] ?? null,
                'neighbor_ip' => $neighbor['neighbor_ip'] ?? null,
                'neighbor_port' => $neighbor['neighbor_port'] ?? null,
                'protocol' => $neighbor['protocol'] ?? 'unknown', // CDP | LLDP | MikroTik | other
                'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
        }

        return $neighbors;
    }

    /**
     * Get neighbor table (ARP/NDP/MAC table) from device
     *
     * @param object $adapter Device adapter
     * @param ObjectId $deviceId
     * @return array Neighbor table entries {mac_address, ip, port, learned_via}
     */
    private function getNeighborTable($adapter, $deviceId): array
    {
        try {
            $neighborTable = $adapter->getNeighborTable();
        } catch (\Exception $e) {
            return [];
        }

        $entries = [];
        foreach ($neighborTable as $entry) {
            $entries[] = [
                'mac_address' => $entry['mac_address'] ?? null,
                'ip' => $entry['ip'] ?? null,
                'ip_version' => $entry['ip_version'] ?? null,
                'port' => $entry['port'] ?? null,
                'learned_via' => $entry['learned_via'] ?? 'arp', // arp | ndp | mac_table
                'ttl' => $entry['ttl'] ?? null,
                'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
        }

        return $entries;
    }

    /**
     * Update device ports in database
     *
     * @param ObjectId $deviceId
     * @param array $interfaces
     */
    private function updateDevicePorts($deviceId, array $interfaces): void
    {
        // Convert interfaces to port objects
        $ports = [];
        foreach ($interfaces as $iface) {
            $ports[] = [
                'name' => $iface['name'],
                'type' => $iface['type'],
                'status' => $iface['status'],
                'ip' => $iface['ip'],
                'ip_version' => $iface['ip_version'],
                'mac_address' => $iface['mac_address'],
                'speed_mbps' => $iface['speed_mbps'],
                'mtu' => $iface['mtu'],
                'description' => $iface['description'],
                'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
        }

        $this->devicesCollection->updateOne(
            ['_id' => $deviceId],
            ['$set' => ['ports' => $ports]]
        );
    }

    /**
     * Discover all nodes in the network
     * Iterates through all devices and discovers each one
     *
     * @param callable $adapterFactory Function that returns adapter for a device
     * @return array Summary of discovery results {successful, failed, total}
     */
    public function discoverAllNodes(callable $adapterFactory): array
    {
        $devices = $this->devicesCollection->find(
            ['status' => ['$ne' => 'decommissioned']],
            ['limit' => 1000]
        );

        $results = [
            'successful' => 0,
            'failed' => 0,
            'total' => 0,
            'discovered_nodes' => [],
            'errors' => [],
        ];

        foreach ($devices as $device) {
            $results['total']++;

            try {
                $adapter = $adapterFactory($device);
                if (!$adapter) {
                    throw new \Exception("No adapter available for {$device->vendor}");
                }

                $nodeData = $this->discoverNode($device->_id, $adapter);
                $results['discovered_nodes'][] = $nodeData;
                $results['successful']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'device_id' => (string)$device->_id,
                    'device_name' => $device->name ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Discover nodes (legacy method)
     *
     * @param array $knownDeviceIds
     * @return array
     */
    public function discover(array $knownDeviceIds = []): array
    {
        // Legacy method kept for compatibility
        return [];
    }
}
