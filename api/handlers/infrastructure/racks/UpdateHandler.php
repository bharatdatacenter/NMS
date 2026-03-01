<?php

declare(strict_types=1);

/**
 * PUT /api/racks/{id}
 *
 * Update rack metadata. Recalculates utilization if specs change.
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

    if (empty($body)) {
        Response::error('No fields to update', 400);
    }

    $updated = $manager->update($id, $body);
    $rack    = $manager->findById($id);

    Response::json(['data' => $rack, 'updated' => $updated]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid input: ' . $e->getMessage(), 400);
} catch (\Exception $e) {
    Response::error('Failed to update rack: ' . $e->getMessage(), 500);
}
