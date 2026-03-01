<?php

declare(strict_types=1);

/**
 * PUT /api/cables/{id}
 *
 * Update cable attributes. Invalidates affected connectivity_paths
 * and re-materializes from updated endpoint.
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

    if (empty($body)) {
        Response::error('No fields to update', 400);
    }

    $updated = $manager->update($id, $body);
    $cable   = $manager->findById($id);

    Response::json(['data' => $cable, 'updated' => $updated]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage(), []);
} catch (\Exception $e) {
    Response::error('Failed to update cable: ' . $e->getMessage(), 500);
}
