<?php

declare(strict_types=1);

/**
 * DELETE /api/cables/{id}
 *
 * Remove a cable. Invalidates all connectivity_paths that used this cable.
 */

use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Cable ID required', 400);
    }

    $manager = new CableManager();
    $cable   = $manager->findById($id);
    if (!$cable) {
        Response::error('Cable not found', 404);
    }

    $deleted = $manager->delete($id);

    Response::json(['deleted' => $deleted, 'id' => $id]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cable ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to delete cable: ' . $e->getMessage(), 500);
}
