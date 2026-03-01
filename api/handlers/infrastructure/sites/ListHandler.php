<?php

declare(strict_types=1);

/**
 * GET /api/sites
 *
 * List all sites with optional filters:
 *   ?status=active&type=datacenter&search=DC1&page=1&per_page=50
 */

use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new SiteManager();

    $filters = array_intersect_key($request['query'] ?? [], array_flip(['status', 'type', 'search']));
    $page    = max(1, (int)($request['query']['page']     ?? 1));
    $perPage = min(200, max(1, (int)($request['query']['per_page'] ?? 50)));

    $result = $manager->list($filters, $page, $perPage);

    Response::paginated($result['data'], $result['total'], $result['page'], $result['per_page']);

} catch (\Exception $e) {
    Response::error('Failed to list sites: ' . $e->getMessage(), 500);
}
