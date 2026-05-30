<?php

declare(strict_types=1);

/**
 * DELETE /api/notifications/rules/{id} — delete a notification routing rule
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Notifications\RuleResolver;

try {
    $params = $request['params'] ?? [];
    $id     = (string)($params['id'] ?? '');

    if ($id === '') {
        Response::error('Rule ID required', 400);
    }

    $resolver = new RuleResolver();
    $deleted  = $resolver->delete($id);

    if (!$deleted) {
        Response::notFound('Notification rule not found');
    }

    Response::success([], 'Notification rule deleted');
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid rule ID', 400, ['message' => $e->getMessage()]);
} catch (\Exception $e) {
    Response::error('Failed to delete notification rule: ' . $e->getMessage(), 500);
}
