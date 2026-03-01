<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

/**
 * TopologyBuilder — stub for Phase 2.
 * Full implementation in Phase 7 (Topology & Discovery).
 */
class TopologyBuilder
{
    /**
     * Build full topology data for a site or global view.
     * Phase 7: aggregates sites, racks, devices, cables into structured graph.
     */
    public function build(array $scope = []): array
    {
        // TODO Phase 7
        return [
            'sites'  => [],
            'nodes'  => [],
            'links'  => [],
            'status' => 'stub',
        ];
    }

    /**
     * Compute topology for a specific site.
     * Phase 7: uses NodeDiscovery + LinkDiscovery.
     */
    public function buildForSite(string $siteId): array
    {
        // TODO Phase 7
        return ['site_id' => $siteId, 'nodes' => [], 'links' => [], 'status' => 'stub'];
    }
}
