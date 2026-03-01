<?php

declare(strict_types=1);

/**
 * GET /api/routes/{id}
 */

use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new RouteManager();
    $route   = $manager->findById($id);

    if (!$route) {
        Response::notFound('Route not found');
    }

    Response::json(['data' => $route]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to fetch route: ' . $e->getMessage(), 500);
}
