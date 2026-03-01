<?php

declare(strict_types=1);

/**
 * PUT /api/firewall/policies/{id}
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

    $ok = $manager->update($id, $body, $user['id'] ?? '000000000000000000000000');

    Response::json(['data' => ['updated' => $ok]]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to update policy: ' . $e->getMessage(), 500);
}
