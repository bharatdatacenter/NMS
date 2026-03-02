<?php

declare(strict_types=1);

/**
 * GET /api/provision/manual-queue
 *
 * List manual intervention queue items (failed compensation steps).
 * Query params: status (open|acknowledged|resolved), page, per_page
 */

use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $filter  = [];
    $page    = max(1, (int)($query['page'] ?? 1));
    $perPage = min(100, max(1, (int)($query['per_page'] ?? 20)));

    // Default to open items
    $filter['status'] = $query['status'] ?? 'open';

    $db         = MongoDB::getInstance();
    $collection = $db->selectCollection('manual_intervention_queue');

    $total  = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'skip'  => ($page - 1) * $perPage,
        'limit' => $perPage,
        'sort'  => ['created_at' => -1],
    ]);

    $items = [];
    foreach ($cursor as $doc) {
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        }
        $items[] = $arr;
    }

    Response::json([
        'data'      => $items,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int)ceil($total / max(1, $perPage)),
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list manual queue: ' . $e->getMessage(), 500);
}
