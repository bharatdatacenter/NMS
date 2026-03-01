<?php

declare(strict_types=1);

/**
 * POST /api/firewall/policies/reorder
 *
 * Reorder firewall policies by updating their sequence numbers.
 *
 * Required body: items[] — array of {id, sequence}
 */

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $v = new Validator();
    $v->validate($body, [
        'items' => 'required',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    if (!is_array($body['items'])) {
        Response::unprocessable('items must be an array');
    }

    $manager = new PolicyManager();
    $manager->reorder($body['items']);

    Response::json(['data' => ['reordered' => true]]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Failed to reorder policies: ' . $e->getMessage(), 500);
}
