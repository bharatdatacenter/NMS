<?php

declare(strict_types=1);

/**
 * GET /api/provision/jobs
 *
 * List provisioning jobs.
 * Query params: status, server_id, page, per_page
 */

use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $filter  = [];
    $page    = max(1, (int)($query['page'] ?? 1));
    $perPage = min(100, max(1, (int)($query['per_page'] ?? 20)));

    if (!empty($query['status'])) {
        $filter['status'] = $query['status'];
    }
    if (!empty($query['server_id'])) {
        $filter['server_id'] = $query['server_id'];
    }

    $db         = MongoDB::getInstance();
    $collection = $db->selectCollection('provisioning_jobs');

    $total  = $collection->countDocuments($filter);
    $cursor = $collection->find($filter, [
        'skip'  => ($page - 1) * $perPage,
        'limit' => $perPage,
        'sort'  => ['created_at' => -1],
    ]);

    $jobs = [];
    foreach ($cursor as $doc) {
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        }
        $jobs[] = $arr;
    }

    Response::json([
        'data'      => $jobs,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int)ceil($total / max(1, $perPage)),
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list jobs: ' . $e->getMessage(), 500);
}
