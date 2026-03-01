<?php

declare(strict_types=1);

/**
 * POST /api/routes/{id}/sync
 *
 * Push a static route to its device via the vendor adapter.
 * If the device is in an HA cluster, pushes to cluster management_ip.
 */

use NMS\Core\Models\Routing\RouteSync;
use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new RouteManager();
    $route   = $manager->findById($id);

    if (!$route) {
        Response::notFound('Route not found');
    }

    $sync = new RouteSync();
    $ok   = $sync->syncToDevice($id);

    Response::json(['data' => ['synced' => $ok]]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Sync failed: ' . $e->getMessage(), 500);
}
