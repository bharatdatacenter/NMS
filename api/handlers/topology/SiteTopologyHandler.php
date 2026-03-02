<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Api\Handlers\BaseHandler;

/**
 * GET /api/topology/site/{site_id} — Get topology for a specific site
 */
class SiteTopologyHandler extends BaseHandler
{
    public function handle(): array
    {
        $siteId = $this->getParam('site_id');
        if (!$siteId) {
            throw new \InvalidArgumentException('site_id required');
        }

        $topologyBuilder = new TopologyBuilder();
        return $topologyBuilder->buildForSite($siteId);
    }
}
