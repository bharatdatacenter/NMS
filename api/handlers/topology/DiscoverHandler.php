<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Services\Scheduler\TopologyRefresh;
use NMS\Api\Handlers\BaseHandler;

/**
 * POST /api/topology/discover — Trigger topology discovery
 */
class DiscoverHandler extends BaseHandler
{
    public function handle(): array
    {
        $topologyRefresh = new TopologyRefresh();
        return $topologyRefresh->refresh();
    }
}
