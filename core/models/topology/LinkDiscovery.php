<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

/**
 * LinkDiscovery — stub for Phase 2.
 * Full implementation in Phase 7 (Topology & Discovery).
 */
class LinkDiscovery
{
    /**
     * Discover links (cables) between nodes using ARP, NDP, LLDP, CDP data.
     * Phase 7: cross-references neighbor data to create/update cable records.
     */
    public function discover(array $nodes = []): array
    {
        // TODO Phase 7
        return [];
    }
}
