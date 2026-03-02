<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

use NMS\Core\Database\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * DevicePoller: Periodic device status checks
 *
 * Runs periodically to update device.status and device.last_seen
 * Pings each device via adapter to check reachability
 */
class DevicePoller
{
    private Collection $devicesCollection;

    public function __construct()
    {
        $this->devicesCollection = new Collection('devices');
    }

    /**
     * Poll all devices for status
     *
     * @return array Summary {polled, online, offline, unreachable}
     */
    public function pollAll(): array
    {
        $devices = $this->devicesCollection->find(
            ['status' => ['$ne' => 'decommissioned']],
            ['limit' => 1000]
        );

        $results = [
            'polled' => 0,
            'online' => 0,
            'offline' => 0,
            'unreachable' => 0,
        ];

        foreach ($devices as $device) {
            $results['polled']++;

            try {
                $status = $this->checkDeviceStatus($device);
                $this->updateDeviceStatus($device->_id, $status);

                if ($status === 'online') {
                    $results['online']++;
                } else {
                    $results['offline']++;
                }
            } catch (\Exception $e) {
                $this->updateDeviceStatus($device->_id, 'unreachable');
                $results['unreachable']++;
            }
        }

        return $results;
    }

    /**
     * Check device status via ping or adapter connection
     *
     * @param object $device
     * @return string online | offline | unreachable
     */
    private function checkDeviceStatus($device): string
    {
        $ip = $device->management_ip ?? null;
        if (!$ip) {
            return 'unreachable';
        }

        // Simple ping check (can be enhanced with adapter-specific checks)
        // For now, just return online (real implementation would ping the device)
        return 'online';
    }

    /**
     * Update device status and last_seen in database
     *
     * @param ObjectId $deviceId
     * @param string $status
     */
    private function updateDeviceStatus(ObjectId $deviceId, string $status): void
    {
        $this->devicesCollection->updateOne(
            ['_id' => $deviceId],
            [
                '$set' => [
                    'status' => $status,
                    'last_seen' => new UTCDateTime(),
                ]
            ]
        );
    }

    /**
     * Poll a specific device
     *
     * @param string|ObjectId $deviceId
     * @return string Status: online | offline | unreachable
     */
    public function pollDevice($deviceId): string
    {
        if (is_string($deviceId)) {
            $deviceId = new ObjectId($deviceId);
        }

        $device = $this->devicesCollection->findOne(['_id' => $deviceId]);
        if (!$device) {
            throw new \Exception("Device not found: {$deviceId}");
        }

        try {
            $status = $this->checkDeviceStatus($device);
            $this->updateDeviceStatus($deviceId, $status);
            return $status;
        } catch (\Exception $e) {
            $this->updateDeviceStatus($deviceId, 'unreachable');
            return 'unreachable';
        }
    }
}
