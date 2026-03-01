<?php

declare(strict_types=1);

/**
 * GET /api/ipam/pools/{id}/usage
 *
 * Return usage statistics for a pool: total, used, reserved, released, active counts.
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $pool    = $manager->findPoolById($params['id']);

    if (!$pool) {
        Response::notFound('Pool not found');
    }

    $stats = $manager->getUsageStats($params['id']);

    Response::json(['data' => $stats]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to get pool usage: ' . $e->getMessage(), 500);
}
