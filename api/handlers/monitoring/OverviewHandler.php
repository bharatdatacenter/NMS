<?php

declare(strict_types=1);

/**
 * GET /api/monitoring/overview
 */

use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Monitoring\ZabbixClient;

try {
    $query = $request['query'] ?? [];
    $limit = min(200, max(1, (int)($query['limit'] ?? 50)));

    $devicesCollection = MongoDB::getInstance()->selectCollection('devices');
    $cursor = $devicesCollection->find([
        'status' => ['$ne' => 'decommissioned'],
    ], [
        'limit' => $limit,
        'sort' => ['name' => 1],
    ]);

    $client = new ZabbixClient();

    $summary = [
        'total_devices' => 0,
        'mapped_devices' => 0,
        'available_devices' => 0,
        'unavailable_devices' => 0,
        'active_alerts' => 0,
    ];

    $rows = [];

    foreach ($cursor as $doc) {
        $summary['total_devices']++;
        $device = json_decode(json_encode($doc), true);
        $deviceId = (string)($device['_id']['$oid'] ?? $device['_id'] ?? '');

        $hostId = $client->resolveHostId($device);
        if ($hostId === null) {
            $rows[] = [
                'device_id' => $deviceId,
                'device_name' => (string)($device['name'] ?? ''),
                'mapped' => false,
                'available' => false,
                'alert_count' => 0,
            ];
            continue;
        }

        $summary['mapped_devices']++;

        $available = $client->getAvailability($hostId);
        $alerts = $client->getActiveAlerts($hostId);
        $alertCount = (int)($alerts['count'] ?? 0);

        if ($available) {
            $summary['available_devices']++;
        } else {
            $summary['unavailable_devices']++;
        }
        $summary['active_alerts'] += $alertCount;

        $rows[] = [
            'device_id' => $deviceId,
            'device_name' => (string)($device['name'] ?? ''),
            'zabbix_host_id' => $hostId,
            'mapped' => true,
            'available' => $available,
            'alert_count' => $alertCount,
        ];
    }

    Response::json([
        'data' => $rows,
        'summary' => $summary,
    ]);
} catch (Response) {
    // Already sent.
} catch (\Exception $e) {
    Response::error('Failed to build monitoring overview: ' . $e->getMessage(), 500);
}
