<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Audit;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

/**
 * ChangesHandler
 *
 * GET /api/audit/changes/{resource} — get configuration change history for a resource
 *
 * resource: device_id, firewall_policy_id, route_id, etc.
 * Queries device_config_changes collection for section-by-section diff history.
 */
class ChangesHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'GET') {
            Response::error('Method not allowed', 405);
            return;
        }

        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.audit.read', $perms, true)) {
            Response::error('Forbidden: nms.audit.read required', 403);
            return;
        }

        $resourceId = $request['params']['resource'] ?? '';
        if (empty($resourceId)) {
            Response::error('Resource ID required', 400);
            return;
        }

        $query      = $request['query'] ?? [];
        $page       = max(1, (int)($query['page'] ?? 1));
        $perPage    = min(100, max(10, (int)($query['per_page'] ?? 20)));
        $skip       = ($page - 1) * $perPage;

        $db         = MongoDB::getInstance()->getDatabase();
        $collection = $db->selectCollection('device_config_changes');

        // Try to use as ObjectId if it looks like one, otherwise match as string
        $filter = ['$or' => [['resource_id' => $resourceId]]];
        if (preg_match('/^[0-9a-f]{24}$/i', $resourceId)) {
            $filter = ['$or' => [
                ['resource_id' => $resourceId],
                ['resource_id' => new ObjectId($resourceId)],
            ]];
        }

        // Additional filters
        if (!empty($query['section'])) {
            $filter['section'] = $query['section'];
        }
        if (!empty($query['changed_by'])) {
            $filter['changed_by'] = $query['changed_by'];
        }

        $total  = $collection->countDocuments($filter);
        $cursor = $collection->find($filter, [
            'sort'  => ['changed_at' => -1],
            'skip'  => $skip,
            'limit' => $perPage,
        ]);

        $changes = array_map($this->normalize(...), iterator_to_array($cursor, false));
        Response::paginated($changes, $total, $page, $perPage);
    }

    private function normalize(object|array $doc): array
    {
        $doc = (array)$doc;
        return [
            'id'          => (string)($doc['_id'] ?? ''),
            'resource_id' => $doc['resource_id'] instanceof ObjectId
                ? (string)$doc['resource_id']
                : ($doc['resource_id'] ?? null),
            'section'     => $doc['section'] ?? null,
            'before'      => $doc['before'] ?? null,
            'after'       => $doc['after'] ?? null,
            'diff'        => $doc['diff'] ?? null,
            'changed_by'  => $doc['changed_by'] ?? null,
            'source'      => $doc['source'] ?? null,   // 'api' | 'drift_resolution'
            'changed_at'  => $doc['changed_at'] instanceof UTCDateTime
                ? $doc['changed_at']->toDateTime()->format('c')
                : null,
        ];
    }
}
