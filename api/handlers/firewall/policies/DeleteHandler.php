<?php

declare(strict_types=1);

/**
 * DELETE /api/firewall/policies/{id}
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

    $manager->delete($id, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['deleted' => true]]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to delete policy: ' . $e->getMessage(), 500);
}
