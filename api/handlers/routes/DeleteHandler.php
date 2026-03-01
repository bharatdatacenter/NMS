<?php

declare(strict_types=1);

/**
 * DELETE /api/routes/{id}
 *
 * Removes route from DB and optionally removes from device if synced.
 * Optional query param: remove_from_device=true
 */

use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Models\Routing\RouteSync;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new RouteManager();
    $route   = $manager->findById($id);

    if (!$route) {
        Response::notFound('Route not found');
    }

    // Optionally remove from device first
    if (!empty($query['remove_from_device']) && $route['synced_to_device']) {
        try {
            $sync = new RouteSync();
            $sync->removeFromDevice($id);
        } catch (\Exception $e) {
            // Log but do not block deletion
        }
    }

    $manager->delete($id, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['deleted' => true]]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to delete route: ' . $e->getMessage(), 500);
}
