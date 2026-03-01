<?php

declare(strict_types=1);

/**
 * GET /api/cables/{id}
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

    Response::json(['data' => $cable]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid cable ID', 400);
} catch (\Exception $e) {
    Response::error('Failed to retrieve cable: ' . $e->getMessage(), 500);
}
