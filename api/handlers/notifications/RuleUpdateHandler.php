<?php

declare(strict_types=1);

/**
 * PUT /api/notifications/rules/{id} — update a notification routing rule
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Notifications\RuleResolver;

try {
    $params = $request['params'] ?? [];
    $body   = $request['body'] ?? [];
    $id     = (string)($params['id'] ?? '');

    if ($id === '') {
        Response::error('Rule ID required', 400);
    }

    $resolver = new RuleResolver();
    $updated  = $resolver->update($id, $body);

    if (!$updated) {
        Response::notFound('Notification rule not found');
    }

    Response::success([], 'Notification rule updated');
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to update notification rule: ' . $e->getMessage(), 500);
}
