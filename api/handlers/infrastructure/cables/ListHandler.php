<?php

declare(strict_types=1);

/**
 * GET /api/cables
 *
 * List cables with optional filters:
 *   ?status=active&cable_type=cat6a&page=1&per_page=50
 */

use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new CableManager();

    $filters = array_intersect_key($request['query'] ?? [], array_flip(['status', 'cable_type']));
    $page    = max(1, (int)($request['query']['page']     ?? 1));
    $perPage = min(200, max(1, (int)($request['query']['per_page'] ?? 50)));

    $result = $manager->list($filters, $page, $perPage);

    Response::paginated($result['data'], $result['total'], $result['page'], $result['per_page']);

} catch (\Exception $e) {
    Response::error('Failed to list cables: ' . $e->getMessage(), 500);
}
