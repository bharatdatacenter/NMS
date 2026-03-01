<?php

declare(strict_types=1);

/**
 * POST /api/racks
 *
 * Create a new rack within a site.
 *
 * Required: site_id, name
 * Optional: label, location (building/floor/room/row/position), specs, notes, status
 */

use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'site_id' => 'required|string',
        'name'    => 'required|string',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate site exists
    $siteManager = new SiteManager();
    $site = $siteManager->findById($body['site_id']);
    if (!$site) {
        Response::error('Site not found', 404);
    }

    $manager = new RackManager();
    $id      = $manager->create($body);
    $rack    = $manager->findById($id);

    // Refresh site stats
    $siteManager->refreshStats($body['site_id']);

    Response::json(['data' => $rack], 201);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to create rack: ' . $e->getMessage(), 500);
}
