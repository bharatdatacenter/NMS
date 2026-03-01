<?php

declare(strict_types=1);

/**
 * DELETE /api/neighbors/{id}
 */

use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new NeighborManager();
    $entry   = $manager->findById($id);

    if (!$entry) {
        Response::notFound('Neighbor entry not found');
    }

    $manager->delete($id);

    Response::json(['data' => ['deleted' => true]]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to delete neighbor entry: ' . $e->getMessage(), 500);
}
