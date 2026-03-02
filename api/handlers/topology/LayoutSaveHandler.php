<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Topology;

use NMS\Core\Database\Collection;
use NMS\Api\Handlers\BaseHandler;
use MongoDB\BSON\ObjectId;

/**
 * POST /api/topology/layout/save — Save topology node positions
 */
class LayoutSaveHandler extends BaseHandler
{
    public function handle(): array
    {
        $body = $this->getBody();

        if (!isset($body['node_positions']) || !is_array($body['node_positions'])) {
            throw new \InvalidArgumentException('node_positions array required');
        }

        $topologyViewsCollection = new Collection('topology_views');

        $layout = [
            'name' => $body['name'] ?? 'User Layout',
            'description' => $body['description'] ?? '',
            'node_positions' => $body['node_positions'],
            'view_type' => $body['view_type'] ?? 'logical',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        $result = $topologyViewsCollection->insertOne($layout);

        return [
            'id' => (string)$result->getInsertedId(),
            'name' => $layout['name'],
            'message' => 'Layout saved',
        ];
    }
}
