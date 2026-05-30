<?php

declare(strict_types=1);

/**
 * POST /api/notifications/rules — create a notification routing rule
 *
 * Body: { name, event_type, min_severity?, enabled?, targets: [ {channel, address} ] }
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Notifications\RuleResolver;

try {
    $body = $request['body'] ?? [];
    $resolver = new RuleResolver();

    $id = $resolver->create($body);

    Response::json(['data' => ['id' => $id]], 201);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to create notification rule: ' . $e->getMessage(), 500);
}
