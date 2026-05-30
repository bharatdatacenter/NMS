<?php

declare(strict_types=1);

/**
 * GET /api/monitoring/device/{id}/traffic
 */

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Monitoring\ZabbixClient;

try {
    $params = $request['params'] ?? [];
    $deviceId = (string)($params['id'] ?? '');

    if ($deviceId === '') {
        Response::error('Device ID required', 400);
    }

    $deviceManager = new DeviceManager();
    $device = $deviceManager->findById($deviceId);
    if ($device === null) {
        Response::notFound('Device not found');
    }

    $client = new ZabbixClient();
    $hostId = $client->resolveHostId($device);

    if ($hostId === null) {
        Response::error('Device is not mapped to a Zabbix host', 422);
    }

    MongoDB::getInstance()->selectCollection('devices')->updateOne(
        ['_id' => new ObjectId($deviceId)],
        ['$set' => [
            'zabbix.host_id' => $hostId,
            'zabbix.last_synced' => new \MongoDB\BSON\UTCDateTime(),
        ]]
    );

    $traffic = $client->getInterfaceTraffic($hostId);

    Response::json([
        'data' => [
            'device_id' => $deviceId,
            'zabbix_host_id' => $hostId,
            'traffic' => $traffic,
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\InvalidArgumentException $e) {
    Response::error('Invalid device ID', 400, ['message' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Exception $e) {
    Response::error('Failed to retrieve device traffic: ' . $e->getMessage(), 500);
}
