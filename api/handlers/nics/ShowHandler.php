<?php

declare(strict_types=1);

/**
 * GET /api/nics/{id}
 *
 * Show a single NIC by ID.
 */

use NMS\Core\Models\Nics\NicManager;
use NMS\Core\Helpers\Response;

try {
    $id = (string)($params['id'] ?? '');
    if ($id === '') {
        Response::notFound('NIC ID is required');
    }

    $manager = new NicManager();
    $nic     = $manager->findById($id);

    if ($nic === null) {
        Response::notFound("NIC {$id} not found");
    }

    Response::json(['data' => $nic]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to retrieve NIC: ' . $e->getMessage(), 500);
}
