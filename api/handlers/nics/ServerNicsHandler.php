<?php

declare(strict_types=1);

/**
 * GET /api/nics/server/{ims_server_id}
 *
 * List all NICs for a specific IMS server.
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;

try {
    $serverId = (string)($params['ims_server_id'] ?? '');
    if ($serverId === '') {
        Response::notFound('IMS server ID is required');
    }

    $manager = new NicManager();
    $nics    = $manager->findByServerId($serverId);

    Response::json(['data' => $nics, 'total' => count($nics)]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to list server NICs: ' . $e->getMessage(), 500);
}
