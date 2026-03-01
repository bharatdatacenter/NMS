<?php

declare(strict_types=1);

/**
 * GET /api/firewall/policies/{id}
 */

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Helpers\Response;

try {
    $id      = $params['id'] ?? '';
    $manager = new PolicyManager();
    $policy  = $manager->findById($id);

    if (!$policy) {
        Response::notFound('Policy not found');
    }

    Response::json(['data' => $policy]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to fetch policy: ' . $e->getMessage(), 500);
}
