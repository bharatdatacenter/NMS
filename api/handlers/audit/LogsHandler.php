<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Audit;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

/**
 * LogsHandler
 *
 * GET /api/audit/logs — list audit log entries with filters
 *
 * Filterable by: user_id, resource_type, resource_id, action, date range
 */
class LogsHandler
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

        $query  = $request['query'] ?? [];
        $filter = $this->buildFilter($query);

        $page    = max(1, (int)($query['page'] ?? 1));
        $perPage = min(200, max(10, (int)($query['per_page'] ?? 50)));
        $skip    = ($page - 1) * $perPage;

        $db         = MongoDB::getInstance()->getDatabase();
        $collection = $db->selectCollection('audit_logs');

        $sort = ['created_at' => -1];
        if (!empty($query['sort_by']) && in_array($query['sort_by'], ['created_at', 'action', 'resource_type'], true)) {
            $dir  = ($query['sort_dir'] ?? 'desc') === 'asc' ? 1 : -1;
            $sort = [$query['sort_by'] => $dir];
        }

        $total  = $collection->countDocuments($filter);
        $cursor = $collection->find($filter, [
            'sort'  => $sort,
            'skip'  => $skip,
            'limit' => $perPage,
        ]);

        $logs = array_map($this->normalize(...), iterator_to_array($cursor, false));
        Response::paginated($logs, $total, $page, $perPage);
    }

    private function buildFilter(array $query): array
    {
        $filter = [];

        if (!empty($query['user_id'])) {
            $filter['user_id'] = new ObjectId($query['user_id']);
        }
        if (!empty($query['resource_type'])) {
            $filter['resource_type'] = $query['resource_type'];
        }
        if (!empty($query['resource_id'])) {
            $filter['resource_id'] = $query['resource_id'];
        }
        if (!empty($query['action'])) {
            $filter['action'] = $query['action'];
        }
        if (!empty($query['method'])) {
            $filter['method'] = strtoupper($query['method']);
        }
        if (!empty($query['search'])) {
            $filter['$or'] = [
                ['path'   => new Regex($query['search'], 'i')],
                ['action' => new Regex($query['search'], 'i')],
            ];
        }

        // Date range filter
        $dateFilter = [];
        if (!empty($query['from'])) {
            $ts = strtotime($query['from']);
            if ($ts !== false) {
                $dateFilter['$gte'] = new UTCDateTime($ts * 1000);
            }
        }
        if (!empty($query['to'])) {
            $ts = strtotime($query['to']);
            if ($ts !== false) {
                $dateFilter['$lte'] = new UTCDateTime($ts * 1000);
            }
        }
        if (!empty($dateFilter)) {
            $filter['created_at'] = $dateFilter;
        }

        return $filter;
    }

    private function normalize(object|array $doc): array
    {
        $doc = (array)$doc;
        return [
            'id'            => (string)($doc['_id'] ?? ''),
            'user_id'       => $doc['user_id'] ? (string)$doc['user_id'] : null,
            'resource_type' => $doc['resource_type'] ?? null,
            'resource_id'   => $doc['resource_id'] ?? null,
            'action'        => $doc['action'] ?? null,
            'method'        => $doc['method'] ?? null,
            'path'          => $doc['path'] ?? null,
            'status_code'   => $doc['status_code'] ?? null,
            'ip_address'    => $doc['ip_address'] ?? null,
            'user_agent'    => $doc['user_agent'] ?? null,
            'created_at'    => $doc['created_at'] instanceof UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : null,
        ];
    }
}
