<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Audit;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

/**
 * LogsExportHandler
 *
 * GET /api/audit/logs/export — export audit logs as CSV
 *
 * Supports same filters as LogsHandler. Returns CSV download.
 * Capped at 10,000 records per export.
 */
class LogsExportHandler
{
    private const MAX_EXPORT_ROWS = 10000;

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

        $db         = MongoDB::getInstance()->getDatabase();
        $collection = $db->selectCollection('audit_logs');

        $cursor = $collection->find($filter, [
            'sort'  => ['created_at' => -1],
            'limit' => self::MAX_EXPORT_ROWS,
        ]);

        $rows = iterator_to_array($cursor, false);

        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            Response::error('Failed to open output stream', 500);
            return;
        }

        // Header row
        fputcsv($output, [
            'id', 'user_id', 'resource_type', 'resource_id', 'action',
            'method', 'path', 'status_code', 'ip_address', 'created_at',
        ]);

        foreach ($rows as $doc) {
            $doc = (array)$doc;
            fputcsv($output, [
                (string)($doc['_id'] ?? ''),
                $doc['user_id'] ? (string)$doc['user_id'] : '',
                $doc['resource_type'] ?? '',
                $doc['resource_id'] ?? '',
                $doc['action'] ?? '',
                $doc['method'] ?? '',
                $doc['path'] ?? '',
                $doc['status_code'] ?? '',
                $doc['ip_address'] ?? '',
                $doc['created_at'] instanceof UTCDateTime
                    ? $doc['created_at']->toDateTime()->format('c')
                    : '',
            ]);
        }

        fclose($output);
        exit;
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
        if (!empty($query['action'])) {
            $filter['action'] = $query['action'];
        }

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
}
