<?php

declare(strict_types=1);

/**
 * GET /api/racks/{id}
 *
 * Get rack details including installed_devices list and utilization.
 */

use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Rack ID required', 400);
    }

    $manager = new RackManager();
    $rack    = $manager->findById($id);
    if (!$rack) {
        Response::error('Rack not found', 404);
    }

    Response::json(['data' => $rack]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid rack ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve rack: ' . $e->getMessage(), 500);
}
