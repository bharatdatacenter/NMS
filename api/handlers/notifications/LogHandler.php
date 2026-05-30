<?php

declare(strict_types=1);

/**
 * GET /api/notifications/log — delivery audit log
 *
 * Query: channel?, status?, event_type?, page?, per_page?
 */

use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $query   = $request['query'] ?? [];
    $page    = max(1, (int)($query['page'] ?? 1));
    $perPage = min(200, max(1, (int)($query['per_page'] ?? 50)));

    $filter = [];
    foreach (['channel', 'status', 'event_type'] as $field) {
        if (!empty($query[$field])) {
            $filter[$field] = (string)$query[$field];
        }
    }

    $collection = MongoDB::getInstance()->selectCollection('notification_log');

    $total  = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'skip'  => ($page - 1) * $perPage,
        'limit' => $perPage,
        'sort'  => ['created_at' => -1],
    ]);

    Response::json([
        'data'      => iterator_to_array($cursor, false),
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int)ceil($total / max(1, $perPage)),
    ]);
} catch (Response) {
    // Already sent.
} catch (\Exception $e) {
    Response::error('Failed to read notification log: ' . $e->getMessage(), 500);
}
