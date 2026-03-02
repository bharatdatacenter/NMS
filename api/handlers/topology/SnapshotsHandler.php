<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Api\Handlers\BaseHandler;

/**
 * GET /api/topology/snapshots — List topology snapshots
 */
class SnapshotsHandler extends BaseHandler
{
    public function handle(): array
    {
        $topologyBuilder = new TopologyBuilder();
        return ['snapshots' => $topologyBuilder->getSnapshots()];
    }
}
