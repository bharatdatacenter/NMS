<?php

declare(strict_types=1);

/**
 * GET /api/racks
 *
 * List racks with optional filters:
 *   ?site_id=...&status=active&search=...&page=1&per_page=50
 */

use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new RackManager();

    $filters = array_intersect_key($request['query'] ?? [], array_flip(['site_id', 'status', 'search']));
    $page    = max(1, (int)($request['query']['page']     ?? 1));
    $perPage = min(200, max(1, (int)($request['query']['per_page'] ?? 50)));

    $result = $manager->list($filters, $page, $perPage);

    Response::paginated($result['data'], $result['total'], $result['page'], $result['per_page']);

} catch (\Exception $e) {
    Response::error('Failed to list racks: ' . $e->getMessage(), 500);
}
