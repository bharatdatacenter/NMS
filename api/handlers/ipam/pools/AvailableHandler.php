<?php

declare(strict_types=1);

/**
 * GET /api/ipam/pools/{id}/available
 *
 * Return the count of available (unassigned) IPs in a pool.
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $pool    = $manager->findPoolById($params['id']);

    if (!$pool) {
        Response::notFound('Pool not found');
    }

    $available = $manager->getAvailableCount($params['id']);

    Response::json([
        'data' => [
            'pool_id'         => $params['id'],
            'network'         => $pool['network'],
            'ip_version'      => $pool['ip_version'],
            'available_count' => $available,
            'status'          => $pool['status'],
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to check pool availability: ' . $e->getMessage(), 500);
}
