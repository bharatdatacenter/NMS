<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Api\Handlers\BaseHandler;

/**
 * GET /api/topology — Get global logical topology
 */
class LogicalHandler extends BaseHandler
{
    public function handle(): array
    {
        $topologyBuilder = new TopologyBuilder();
        return $topologyBuilder->build(['type' => 'global']);
    }
}
