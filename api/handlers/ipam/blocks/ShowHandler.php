<?php

declare(strict_types=1);

/**
 * GET /api/ipam/blocks/{id}
 *
 * Get a single IP block by ID.
 */

use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Helpers\Response;

try {
    $manager = new PoolManager();
    $block   = $manager->findBlockById($params['id']);

    if (!$block) {
        Response::notFound('IP block not found');
    }

    Response::json(['data' => $block]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to get IP block: ' . $e->getMessage(), 500);
}
