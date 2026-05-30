<?php

declare(strict_types=1);

/**
 * GET /api/devices/{id}/drift
 */

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;

try {
    $params = $request['params'] ?? [];
    $query = $request['query'] ?? [];

    $deviceId = (string)($params['id'] ?? '');
    if ($deviceId === '') {
        Response::error('Device ID required', 400);
    }

    $filter = ['device_id' => new ObjectId($deviceId)];
    if (!empty($query['status'])) {
        $filter['status'] = (string)$query['status'];
    }

    $collection = MongoDB::getInstance()->selectCollection('config_drift_log');
    $cursor = $collection->find($filter, ['sort' => ['detected_at' => -1], 'limit' => 200]);

    $items = [];
    foreach ($cursor as $doc) {
        $items[] = json_decode(json_encode($doc), true);
    }

    Response::json([
        'data' => $items,
        'meta' => [
            'device_id' => $deviceId,
            'count' => count($items),
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400, ['message' => $e->getMessage()]);
} catch (\Exception $e) {
    Response::error('Failed to get device drift: ' . $e->getMessage(), 500);
}
