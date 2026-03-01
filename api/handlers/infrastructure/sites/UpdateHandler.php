<?php

declare(strict_types=1);

/**
 * PUT /api/sites/{id}
 *
 * Update site metadata. Stats are recomputed automatically on rack/device changes.
 */

use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Site ID required', 400);
    }

    $manager = new SiteManager();
    $site    = $manager->findById($id);
    if (!$site) {
        Response::error('Site not found', 404);
    }

    if (empty($body)) {
        Response::error('No fields to update', 400);
    }

    // If code is changing, check for uniqueness
    if (isset($body['code'])) {
        $newCode   = strtoupper($body['code']);
        $existing  = $manager->findOne(['code' => $newCode]);
        if ($existing && $existing['id'] !== $id) {
            Response::unprocessable('Duplicate code', ['code' => 'Site code already exists']);
        }
    }

    $updated = $manager->update($id, $body);
    $site    = $manager->findById($id);

    Response::json(['data' => $site, 'updated' => $updated]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid input: ' . $e->getMessage(), 400);
} catch (\Exception $e) {
    Response::error('Failed to update site: ' . $e->getMessage(), 500);
}
