<?php

declare(strict_types=1);

/**
 * POST /api/drift/{id}/resolve
 * Body: {"action": "push"|"pull"|"ignore"}
 */

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Drift\DriftDetector;

try {
    $params = $request['params'] ?? [];
    $body = $request['body'] ?? [];

    $driftId = (string)($params['id'] ?? '');
    if ($driftId === '') {
        Response::error('Drift ID required', 400);
    }

    $action = (string)($body['action'] ?? '');
    if (!in_array($action, ['push', 'pull', 'ignore'], true)) {
        Response::unprocessable('Validation failed', ['action' => 'Must be push, pull, or ignore']);
    }

    $detector = new DriftDetector();

    if ($action === 'push') {
        $detector->resolveAsPush($driftId);
    } elseif ($action === 'pull') {
        $detector->resolveAsPull($driftId);
    } else {
        $detector->resolveAsIgnore($driftId);
    }

    Response::json([
        'data' => [
            'drift_id' => $driftId,
            'action' => $action,
            'status' => 'resolved',
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid drift ID', 400, ['message' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Failed to resolve drift: ' . $e->getMessage(), 500);
}
