<?php

declare(strict_types=1);

/**
 * DELETE /api/ipam/pools/{id}
 *
 * Delete a pool. Rejected if pool has active assignments.
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $pool    = $manager->findPoolById($params['id']);

    if (!$pool) {
        Response::notFound('Pool not found');
    }

    $manager->deletePool($params['id']);

    Response::json(['message' => 'Pool deleted successfully']);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to delete pool: ' . $e->getMessage(), 500);
}
