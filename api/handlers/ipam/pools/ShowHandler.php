<?php

declare(strict_types=1);

/**
 * GET /api/ipam/pools/{id}
 *
 * Get a single IP pool by ID.
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $pool    = $manager->findPoolById($params['id']);

    if (!$pool) {
        Response::notFound('Pool not found');
    }

    Response::json(['data' => $pool]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to get pool: ' . $e->getMessage(), 500);
}
