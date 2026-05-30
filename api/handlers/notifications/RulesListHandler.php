<?php

declare(strict_types=1);

/**
 * GET /api/notifications/rules — list notification routing rules
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Notifications\RuleResolver;

try {
    $query = $request['query'] ?? [];
    $resolver = new RuleResolver();

    $result = $resolver->list(
        (int)($query['page'] ?? 1),
        min(200, max(1, (int)($query['per_page'] ?? 50)))
    );

    Response::json($result);
} catch (Response) {
    // Already sent.
} catch (\Exception $e) {
    Response::error('Failed to list notification rules: ' . $e->getMessage(), 500);
}
