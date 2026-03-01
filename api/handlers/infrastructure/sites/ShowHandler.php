<?php

declare(strict_types=1);

/**
 * GET /api/sites/{id}
 *
 * Get a single site with its racks and current stats.
 */

use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Helpers\Response;

try {
    $id = $params['id'] ?? '';
    if (empty($id)) {
        Response::error('Site ID required', 400);
    }

    $siteManager = new SiteManager();
    $site = $siteManager->findById($id);
    if (!$site) {
        Response::error('Site not found', 404);
    }

    // Include racks in this site
    $rackManager = new RackManager();
    $racks = $rackManager->findAll(['site_id' => new \MongoDB\BSON\ObjectId($id)]);

    $site['racks'] = $racks;

    Response::json(['data' => $site]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid site ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve site: ' . $e->getMessage(), 500);
}
