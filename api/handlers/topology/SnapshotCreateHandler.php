<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Models\Topology\TopologyBuilder;
use NMS\Api\Handlers\BaseHandler;

/**
 * POST /api/topology/snapshots — Create topology snapshot
 */
class SnapshotCreateHandler extends BaseHandler
{
    public function handle(): array
    {
        $body = $this->getBody();

        if (!isset($body['name'])) {
            throw new \InvalidArgumentException('name required');
        }

        $topologyBuilder = new TopologyBuilder();
        $snapshot = $topologyBuilder->createSnapshot(
            $body['name'],
            $body['description'] ?? '',
            $body['site_id'] ?? null
        );

        return [
            'id' => (string)$snapshot['_id'] ?? null,
            'name' => $snapshot['name'],
            'message' => 'Snapshot created',
        ];
    }
}
