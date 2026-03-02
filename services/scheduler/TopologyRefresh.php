<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

use NMS\Core\Models\Topology\NodeDiscovery;
use NMS\Core\Models\Topology\LinkDiscovery;
use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Models\Devices\DeviceFactory;

/**
 * TopologyRefresh: Periodic topology discovery runner
 *
 * Runs periodically to:
 * 1. Discover interfaces and neighbors from all devices
 * 2. Match neighbors to registered devices
 * 3. Create/update cable records
 * 4. Recompute connectivity paths
 * 5. Update topology views
 */
class TopologyRefresh
{
    private NodeDiscovery $nodeDiscovery;
    private LinkDiscovery $linkDiscovery;
    private TopologyBuilder $topologyBuilder;
    private PathMaterializer $pathMaterializer;
    private DeviceFactory $deviceFactory;

    public function __construct()
    {
        $this->nodeDiscovery = new NodeDiscovery();
        $this->linkDiscovery = new LinkDiscovery();
        $this->topologyBuilder = new TopologyBuilder();
        $this->pathMaterializer = new PathMaterializer();
        $this->deviceFactory = new DeviceFactory();
    }

    /**
     * Run topology discovery and refresh
     *
     * @return array Summary {nodes_discovered, cables_created, paths_recomputed}
     */
    public function refresh(): array
    {
        try {
            // 1. Discover all nodes
            $discoveryResults = $this->nodeDiscovery->discoverAllNodes(function($device) {
                return $this->deviceFactory->create($device);
            });

            // 2. Link neighbors to create/update cables
            $linkingResults = $this->linkDiscovery->linkAllNeighbors($discoveryResults['discovered_nodes']);

            // 3. Recompute affected connectivity paths
            $pathsRecomputed = $this->recomputePaths($linkingResults);

            // 4. Update topology views
            $this->topologyBuilder->build(['type' => 'global']);

            return [
                'status' => 'success',
                'nodes_discovered' => $discoveryResults['successful'],
                'nodes_failed' => $discoveryResults['failed'],
                'cables_matched' => $linkingResults['total_matched_cables'],
                'unknown_neighbors' => $linkingResults['total_unknown_neighbors'],
                'paths_recomputed' => $pathsRecomputed,
                'timestamp' => new \MongoDB\BSON\UTCDateTime(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => new \MongoDB\BSON\UTCDateTime(),
            ];
        }
    }

    /**
     * Recompute connectivity paths after cable changes
     *
     * @param array $linkingResults
     * @return int Count of paths recomputed
     */
    private function recomputePaths(array $linkingResults): int
    {
        // TODO: Iterate through all invalid paths and recompute
        // For now, just return 0
        return 0;
    }
}
