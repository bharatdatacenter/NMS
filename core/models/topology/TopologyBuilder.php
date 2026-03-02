<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\Collection;

/**
 * TopologyBuilder: Aggregates topology data into API response format
 *
 * Builds {sites, nodes, links} graph with cluster grouping and drift status.
 * Stores in topology_views and topology_snapshots collections.
 */
class TopologyBuilder
{
    private Collection $sitesCollection;
    private Collection $devicesCollection;
    private Collection $clustersCollection;
    private Collection $cablesCollection;
    private Collection $topologyViewsCollection;
    private Collection $topologySnapshotsCollection;

    public function __construct()
    {
        $this->sitesCollection = new Collection('sites');
        $this->devicesCollection = new Collection('devices');
        $this->clustersCollection = new Collection('device_clusters');
        $this->cablesCollection = new Collection('cables');
        $this->topologyViewsCollection = new Collection('topology_views');
        $this->topologySnapshotsCollection = new Collection('topology_snapshots');
    }

    /**
     * Build full topology for global or scoped view
     *
     * @param array $scope {type: 'site'|'global'|'rack', site_id?: string, rack_id?: string}
     * @return array {sites, nodes, links}
     */
    public function build(array $scope = []): array
    {
        $scopeType = $scope['type'] ?? 'global';
        $siteId = $scope['site_id'] ?? null;

        if ($scopeType === 'site' && $siteId) {
            return $this->buildForSite($siteId);
        }

        // Global topology
        $sites = $this->buildSitesData();
        $nodes = $this->buildNodesData($siteId);
        $links = $this->buildLinksData($siteId);

        $topology = [
            'sites' => $sites,
            'nodes' => $nodes,
            'links' => $links,
            'computed_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        // Store/update topology_views
        $this->storeTopologyView($scopeType, $siteId, $topology);

        return $topology;
    }

    /**
     * Build topology for a specific site
     *
     * @param string $siteId
     * @return array {site, nodes, links}
     */
    public function buildForSite(string $siteId): array
    {
        $siteId = $siteId instanceof ObjectId ? $siteId : new ObjectId($siteId);
        $site = $this->sitesCollection->findOne(['_id' => $siteId]);
        if (!$site) {
            throw new \Exception("Site not found: {$siteId}");
        }

        $nodes = $this->buildNodesData($siteId);
        $links = $this->buildLinksData($siteId);

        $siteData = [
            'id' => (string)$site->_id,
            'name' => $site->name ?? null,
            'code' => $site->code ?? null,
            'type' => $site->type ?? 'datacenter',
            'coordinates' => $site->address->coordinates ?? null,
            'stats' => [
                'devices' => count($nodes),
                'racks' => $site->stats->total_racks ?? 0,
                'connections' => count($links),
            ],
        ];

        return [
            'site' => $siteData,
            'nodes' => $nodes,
            'links' => $links,
            'computed_at' => new \MongoDB\BSON\UTCDateTime(),
        ];
    }

    /**
     * Build sites data
     *
     * @return array
     */
    private function buildSitesData(): array
    {
        $sites = $this->sitesCollection->find(
            ['status' => ['$ne' => 'decommissioned']],
            ['limit' => 100]
        );

        $data = [];
        foreach ($sites as $site) {
            $data[] = [
                'id' => (string)$site->_id,
                'name' => $site->name ?? null,
                'code' => $site->code ?? null,
                'coordinates' => $site->address->coordinates ?? null,
                'stats' => [
                    'devices' => $site->stats->total_devices ?? 0,
                    'racks' => $site->stats->total_racks ?? 0,
                ],
            ];
        }

        return $data;
    }

    /**
     * Build nodes (devices) data
     *
     * @param ObjectId|null $siteId Filter by site
     * @return array
     */
    private function buildNodesData(?$siteId = null): array
    {
        $query = ['status' => ['$ne' => 'decommissioned']];
        if ($siteId) {
            $query['site_id'] = $siteId instanceof ObjectId ? $siteId : new ObjectId($siteId);
        }

        $devices = $this->devicesCollection->find($query, ['limit' => 1000]);

        $nodes = [];
        foreach ($devices as $device) {
            // Get cluster info if device is in a cluster
            $clusterInfo = null;
            if ($device->cluster_id ?? null) {
                $cluster = $this->clustersCollection->findOne(['_id' => $device->cluster_id]);
                $clusterInfo = [
                    'id' => (string)($cluster->_id ?? null),
                    'name' => $cluster->name ?? null,
                    'role' => $device->cluster_role ?? 'member', // primary | secondary
                ];
            }

            // Extract ports
            $ports = [];
            foreach (($device->ports ?? []) as $port) {
                $ports[] = [
                    'name' => $port['name'] ?? null,
                    'ip' => $port['ip'] ?? null,
                    'status' => $port['status'] ?? 'unknown',
                    'connected_to' => $port['connected_to'] ?? null,
                ];
            }

            $nodes[] = [
                'id' => (string)$device->_id,
                'type' => $device->role ?? 'network_device',
                'name' => $device->name ?? null,
                'vendor' => $device->vendor ?? null,
                'ip' => $device->management_ip ?? null,
                'status' => $device->status ?? 'unknown',
                'site' => $device->site_name ?? null,
                'rack' => $device->rack_name ?? null,
                'cluster' => $clusterInfo,
                'drift_status' => $device->drift->status ?? 'clean',
                'ports' => $ports,
            ];
        }

        return $nodes;
    }

    /**
     * Build links (cables) data
     *
     * @param ObjectId|null $siteId Filter by site
     * @return array
     */
    private function buildLinksData(?$siteId = null): array
    {
        $query = ['status' => ['$ne' => 'decommissioned']];
        if ($siteId) {
            // Filter cables where either endpoint is in the site
            $query['$or'] = [
                ['endpoint_a.site_id' => $siteId instanceof ObjectId ? $siteId : new ObjectId($siteId)],
                ['endpoint_b.site_id' => $siteId instanceof ObjectId ? $siteId : new ObjectId($siteId)],
            ];
        }

        $cables = $this->cablesCollection->find($query, ['limit' => 5000]);

        $links = [];
        foreach ($cables as $cable) {
            $epA = $cable->endpoint_a;
            $epB = $cable->endpoint_b;

            $links[] = [
                'id' => (string)$cable->_id,
                'source' => (string)$epA->device_id,
                'source_port' => $epA->port_name ?? null,
                'target' => (string)$epB->device_id,
                'target_port' => $epB->port_name ?? null,
                'cable_type' => $cable->cable_type ?? 'ethernet',
                'status' => $cable->status ?? 'unknown',
                'via_patch_panels' => $this->getViaPatches($cable),
            ];
        }

        return $links;
    }

    /**
     * Get patch panels traversed in a cable path
     *
     * @param object $cable
     * @return array
     */
    private function getViaPatches($cable): array
    {
        // Could look up connectivity_paths to get full path with patch panels
        // For now, return empty — detailed in discovery results
        return [];
    }

    /**
     * Store topology view
     *
     * @param string $viewType
     * @param string|null $siteId
     * @param array $topologyData
     */
    private function storeTopologyView(string $viewType, ?string $siteId, array $topologyData): void
    {
        $scope = ['type' => $viewType];
        if ($siteId) {
            $scope['site_id'] = $siteId instanceof ObjectId ? $siteId : new ObjectId($siteId);
        }

        $view = [
            'name' => "Topology - {$viewType}",
            'description' => "Auto-generated {$viewType} topology",
            'view_type' => $viewType,
            'scope' => $scope,
            'data' => $topologyData,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        $this->topologyViewsCollection->insertOne($view);
    }

    /**
     * Create topology snapshot
     *
     * @param string $name
     * @param string $description
     * @param string|null $siteId
     * @return array Snapshot document
     */
    public function createSnapshot(string $name, string $description = '', ?string $siteId = null): array
    {
        // Gather all topology data
        $sites = $this->sitesCollection->find(['status' => ['$ne' => 'decommissioned']]);
        $devices = $this->devicesCollection->find(['status' => ['$ne' => 'decommissioned']]);
        $cables = $this->cablesCollection->find(['status' => ['$ne' => 'decommissioned']]);

        $snapshot = [
            'name' => $name,
            'description' => $description,
            'scope' => $siteId ? ['site_id' => $siteId] : ['type' => 'global'],
            'snapshot_data' => [
                'sites' => [],
                'devices' => [],
                'cables' => [],
            ],
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        // Copy sites
        foreach ($sites as $site) {
            $snapshot['snapshot_data']['sites'][] = $this->documentToArray($site);
        }

        // Copy devices
        foreach ($devices as $device) {
            if ($siteId && (string)($device->site_id ?? '') !== $siteId) {
                continue;
            }
            $snapshot['snapshot_data']['devices'][] = $this->documentToArray($device);
        }

        // Copy cables
        foreach ($cables as $cable) {
            $snapshot['snapshot_data']['cables'][] = $this->documentToArray($cable);
        }

        $this->topologySnapshotsCollection->insertOne($snapshot);
        return $snapshot;
    }

    /**
     * Get topology snapshots
     *
     * @param string|null $siteId
     * @return array
     */
    public function getSnapshots(?string $siteId = null): array
    {
        $query = [];
        if ($siteId) {
            $query['scope.site_id'] = $siteId;
        }

        $snapshots = $this->topologySnapshotsCollection->find($query, ['limit' => 100]);

        $data = [];
        foreach ($snapshots as $snapshot) {
            $data[] = [
                'id' => (string)$snapshot->_id,
                'name' => $snapshot->name,
                'description' => $snapshot->description ?? null,
                'created_at' => $snapshot->created_at,
            ];
        }

        return $data;
    }

    /**
     * Get a specific snapshot
     *
     * @param string $snapshotId
     * @return array
     */
    public function getSnapshot(string $snapshotId): array
    {
        $snapshot = $this->topologySnapshotsCollection->findOne([
            '_id' => $snapshotId instanceof ObjectId ? $snapshotId : new ObjectId($snapshotId)
        ]);

        if (!$snapshot) {
            throw new \Exception("Snapshot not found: {$snapshotId}");
        }

        return $this->documentToArray($snapshot);
    }

    /**
     * Helper: convert BSON document to array
     *
     * @param object $doc
     * @return array
     */
    private function documentToArray($doc): array
    {
        return json_decode(json_encode($doc), true);
    }
}
