<?php

declare(strict_types=1);

/**
 * GET /api/drift
 *
 * Query: status?, device_id?, page?, per_page?
 */

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $query = $request['query'] ?? [];

    $page = max(1, (int)($query['page'] ?? 1));
    $perPage = min(200, max(1, (int)($query['per_page'] ?? 50)));

    $filter = [];
    if (!empty($query['status'])) {
        $filter['status'] = (string)$query['status'];
    }
    if (!empty($query['device_id'])) {
        $filter['device_id'] = new ObjectId((string)$query['device_id']);
    }

    $collection = MongoDB::getInstance()->selectCollection('config_drift_log');

    $total = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'sort' => ['detected_at' => -1],
        'skip' => ($page - 1) * $perPage,
        'limit' => $perPage,
    ]);

    $items = [];
    foreach ($cursor as $doc) {
        $items[] = json_decode(json_encode($doc), true);
    }

    Response::json([
        'data' => $items,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int)ceil($total / max(1, $perPage)),
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid filter', 400, ['message' => $e->getMessage()]);
} catch (\Exception $e) {
    Response::error('Failed to list drift records: ' . $e->getMessage(), 500);
}
