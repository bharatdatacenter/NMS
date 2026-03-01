<?php

declare(strict_types=1);

namespace NMS\Core\Models\Topology;

/**
 * NodeDiscovery — stub for Phase 2.
 * Full implementation in Phase 7 (Topology & Discovery).
 */
class NodeDiscovery
{
    /**
     * Discover nodes (devices) via vendor adapter interface/neighbor data.
     * Phase 7: queries each device via vendor adapter, parses LLDP/CDP/MikroTik neighbor.
     */
    public function discover(array $knownDeviceIds = []): array
    {
        // TODO Phase 7
        return [];
    }
}
