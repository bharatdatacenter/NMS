<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Api\Handlers\BaseHandler;

/**
 * GET /api/topology/physical — Get physical topology (racks, cables, patch panels)
 */
class PhysicalHandler extends BaseHandler
{
    public function handle(): array
    {
        $topologyBuilder = new TopologyBuilder();
        return $topologyBuilder->build(['type' => 'physical']);
    }
}
