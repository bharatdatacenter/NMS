<?php

declare(strict_types=1);

/**
 * GET /api/racks/{id}/diagram
 *
 * Return structured rack elevation data for visual U-position rendering.
 * Response includes a slot map (U1..U_total) showing which device occupies each unit.
 */

use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Rack ID required', 400);
    }

    $manager  = new RackManager();
    $diagram  = $manager->getDiagramData($id);

    if ($diagram === null) {
        Response::error('Rack not found', 404);
    }

    Response::json(['data' => $diagram]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid rack ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to build rack diagram: ' . $e->getMessage(), 500);
}
