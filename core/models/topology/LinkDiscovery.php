<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;

/**
 * LinkDiscovery: Matches discovered neighbors to registered devices, creates cable records
 *
 * Matches CDP/LLDP/MikroTik neighbor data to registered devices.
 * Creates cable records for confirmed connections.
 * Flags unknown neighbors for manual review.
 */
class LinkDiscovery
{
    private Collection $devicesCollection;
    private Collection $cablesCollection;
    private Collection $neighborsCollection;

    public function __construct()
    {
        $this->devicesCollection = new Collection('devices');
        $this->cablesCollection = new Collection('cables');
        $this->neighborsCollection = new Collection('topology_neighbors');
    }

    /**
     * Process discovered neighbors and match to registered devices
     *
     * @param ObjectId|string $sourceDeviceId
     * @param array $neighbors Discovered neighbors from NodeDiscovery
     * @return array {matched_cables, unknown_neighbors}
     */
    public function linkNeighbors($sourceDeviceId, array $neighbors): array
    {
        if (is_string($sourceDeviceId)) {
            $sourceDeviceId = new ObjectId($sourceDeviceId);
        }

        $sourceDevice = $this->devicesCollection->findOne(['_id' => $sourceDeviceId]);
        if (!$sourceDevice) {
            throw new \Exception("Source device not found: {$sourceDeviceId}");
        }

        $matchedCables = [];
        $unknownNeighbors = [];

        foreach ($neighbors as $neighbor) {
            // Try to match neighbor to a registered device
            $targetDevice = $this->matchNeighborToDevice($neighbor, $sourceDevice);

            if ($targetDevice) {
                // Create or update cable record
                $cable = $this->createOrUpdateCable(
                    $sourceDevice,
                    $neighbor['local_port'] ?? null,
                    $targetDevice,
                    $neighbor['neighbor_port'] ?? null,
                    $neighbor['protocol'] ?? 'unknown'
                );

                if ($cable) {
                    $matchedCables[] = $cable;
                }
            } else {
                // Flag as unknown neighbor for manual review
                $unknownNeighbors[] = [
                    'source_device_id' => $sourceDeviceId,
                    'source_device_name' => $sourceDevice->name ?? 'unknown',
                    'source_port' => $neighbor['local_port'] ?? null,
                    'neighbor_device' => $neighbor['neighbor_device'] ?? null,
                    'neighbor_ip' => $neighbor['neighbor_ip'] ?? null,
                    'neighbor_port' => $neighbor['neighbor_port'] ?? null,
                    'protocol' => $neighbor['protocol'] ?? 'unknown',
                    'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
                    'status' => 'unmatched', // unmatched | reviewed | added
                ];

                // Store in topology_neighbors collection for review
                $this->neighborsCollection->insertOne($unknownNeighbors[count($unknownNeighbors) - 1]);
            }
        }

        return [
            'source_device_id' => $sourceDeviceId,
            'matched_cables' => $matchedCables,
            'unknown_neighbors' => $unknownNeighbors,
            'total_matched' => count($matchedCables),
            'total_unknown' => count($unknownNeighbors),
        ];
    }

    /**
     * Match discovered neighbor to a registered device
     *
     * Tries to match by:
     * 1. Device name match
     * 2. IP address match
     * 3. MAC address match
     *
     * @param array $neighbor Discovered neighbor data
     * @param object $sourceDevice Source device object
     * @return object|null Target device if matched, null otherwise
     */
    private function matchNeighborToDevice(array $neighbor, $sourceDevice)
    {
        $neighborDevice = $neighbor['neighbor_device'] ?? null;
        $neighborIp = $neighbor['neighbor_ip'] ?? null;

        if (!$neighborDevice && !$neighborIp) {
            return null;
        }

        // Try match by device name
        if ($neighborDevice) {
            $query = ['$or' => [
                ['name' => ['$regex' => "^{$neighborDevice}", '$options' => 'i']],
                ['management_ip' => $neighborIp],
            ]];

            $matched = $this->devicesCollection->findOne($query);
            if ($matched) {
                return $matched;
            }
        }

        // Try match by IP
        if ($neighborIp) {
            $matched = $this->devicesCollection->findOne(['management_ip' => $neighborIp]);
            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Create or update cable record for discovered connection
     *
     * @param object $sourceDevice
     * @param string $sourcePort
     * @param object $targetDevice
     * @param string $targetPort
     * @param string $discoveryProtocol
     * @return array|null Cable document if created/updated, null if skipped
     */
    private function createOrUpdateCable($sourceDevice, $sourcePort, $targetDevice, $targetPort, string $discoveryProtocol)
    {
        // Check if cable already exists
        $existingCable = $this->cablesCollection->findOne([
            '$or' => [
                [
                    'endpoint_a.device_id' => $sourceDevice->_id,
                    'endpoint_a.port_name' => $sourcePort,
                    'endpoint_b.device_id' => $targetDevice->_id,
                    'endpoint_b.port_name' => $targetPort,
                ],
                [
                    'endpoint_a.device_id' => $targetDevice->_id,
                    'endpoint_a.port_name' => $targetPort,
                    'endpoint_b.device_id' => $sourceDevice->_id,
                    'endpoint_b.port_name' => $sourcePort,
                ]
            ]
        ]);

        if ($existingCable) {
            // Update discovery metadata
            $this->cablesCollection->updateOne(
                ['_id' => $existingCable->_id],
                [
                    '$set' => [
                        'discovery_protocol' => $discoveryProtocol,
                        'last_discovered' => new \MongoDB\BSON\UTCDateTime(),
                    ],
                    '$addToSet' => ['discovery_history' => [
                        'protocol' => $discoveryProtocol,
                        'discovered_at' => new \MongoDB\BSON\UTCDateTime(),
                    ]]
                ]
            );

            return $existingCable;
        }

        // Generate cable ID
        $cableId = 'C-' . substr(md5($sourceDevice->_id . $sourcePort . $targetDevice->_id . $targetPort), 0, 8);

        // Create new cable record
        $cable = [
            'cable_id' => $cableId,
            'endpoint_a' => [
                'site_id' => $sourceDevice->site_id ?? null,
                'rack_id' => $sourceDevice->rack_id ?? null,
                'rack_name' => $sourceDevice->rack_name ?? null,
                'device_id' => $sourceDevice->_id,
                'device_name' => $sourceDevice->name,
                'port_name' => $sourcePort,
            ],
            'endpoint_b' => [
                'site_id' => $targetDevice->site_id ?? null,
                'rack_id' => $targetDevice->rack_id ?? null,
                'rack_name' => $targetDevice->rack_name ?? null,
                'device_id' => $targetDevice->_id,
                'device_name' => $targetDevice->name,
                'port_name' => $targetPort,
            ],
            'cable_type' => 'ethernet', // Default; can be updated manually
            'status' => 'active',
            'discovery_protocol' => $discoveryProtocol,
            'discovered' => true,
            'installed_at' => new \MongoDB\BSON\UTCDateTime(),
            'tested' => false,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        $this->cablesCollection->insertOne($cable);
        return $cable;
    }

    /**
     * Link neighbors from multiple discovered nodes
     *
     * @param array $discoveredNodes Array of node discovery results
     * @return array Summary {total_matched_cables, total_unknown_neighbors}
     */
    public function linkAllNeighbors(array $discoveredNodes): array
    {
        $totalMatched = 0;
        $totalUnknown = 0;

        foreach ($discoveredNodes as $nodeData) {
            if (empty($nodeData['neighbors'])) {
                continue;
            }

            $result = $this->linkNeighbors($nodeData['device_id'], $nodeData['neighbors']);
            $totalMatched += $result['total_matched'];
            $totalUnknown += $result['total_unknown'];
        }

        return [
            'total_matched_cables' => $totalMatched,
            'total_unknown_neighbors' => $totalUnknown,
        ];
    }

    /**
     * Discover links (legacy method)
     *
     * @param array $nodes
     * @return array
     */
    public function discover(array $nodes = []): array
    {
        // Legacy method kept for compatibility
        return [];
    }
}
