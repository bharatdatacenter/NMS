<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

use NMS\Core\Database\Collection;
use NMS\Core\Models\Devices\DeviceFactory;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * BackupScheduler: Periodic config backups per role interval
 *
 * Intervals per device role:
 * - edge_firewall: every 5 minutes
 * - core_router: every 10 minutes
 * - access_switch: every 30 minutes
 */
class BackupScheduler
{
    private Collection $devicesCollection;
    private Collection $backupsCollection;
    private DeviceFactory $deviceFactory;

    // Backup intervals by role (in minutes)
    private array $intervals = [
        'edge_firewall' => 5,
        'core_router' => 10,
        'access_switch' => 30,
    ];

    public function __construct()
    {
        $this->devicesCollection = new Collection('devices');
        $this->backupsCollection = new Collection('device_backups');
        $this->deviceFactory = new DeviceFactory();
    }

    /**
     * Run backups for devices that need them
     *
     * @return array Summary {backed_up, skipped, failed}
     */
    public function runBackups(): array
    {
        $devices = $this->devicesCollection->find(
            ['status' => 'online'],
            ['limit' => 1000]
        );

        $results = [
            'backed_up' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($devices as $device) {
            if ($this->shouldBackupDevice($device)) {
                try {
                    $this->backupDevice($device);
                    $results['backed_up']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                }
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Check if a device should be backed up
     *
     * @param object $device
     * @return bool
     */
    private function shouldBackupDevice($device): bool
    {
        $role = $device->role ?? null;
        $interval = $this->intervals[$role] ?? 60; // Default 60 minutes

        $lastBackup = $device->last_backup_at ?? null;
        if (!$lastBackup) {
            return true; // Never backed up
        }

        $lastBackupTime = $lastBackup->toDateTime()->getTimestamp();
        $nowTime = time();
        $minutesPassed = ($nowTime - $lastBackupTime) / 60;

        return $minutesPassed >= $interval;
    }

    /**
     * Backup a device configuration
     *
     * @param object $device
     * @throws \Exception
     */
    private function backupDevice($device): void
    {
        try {
            $adapter = $this->deviceFactory->create($device);
            if (!$adapter) {
                throw new \Exception("No adapter for {$device->vendor}");
            }

            // Get config from adapter
            $config = $adapter->getConfig();

            // Store backup
            $backup = [
                'device_id' => $device->_id,
                'device_name' => $device->name,
                'vendor' => $device->vendor,
                'config' => $config,
                'timestamp' => new UTCDateTime(),
            ];

            $this->backupsCollection->insertOne($backup);

            // Update device's last_backup_at
            $this->devicesCollection->updateOne(
                ['_id' => $device->_id],
                ['$set' => ['last_backup_at' => new UTCDateTime()]]
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Get backup history for a device
     *
     * @param ObjectId|string $deviceId
     * @param int $limit
     * @return array List of backups
     */
    public function getBackups($deviceId, int $limit = 10): array
    {
        if (is_string($deviceId)) {
            $deviceId = new ObjectId($deviceId);
        }

        $backups = $this->backupsCollection->find(
            ['device_id' => $deviceId],
            ['sort' => ['timestamp' => -1], 'limit' => $limit]
        );

        $results = [];
        foreach ($backups as $backup) {
            $results[] = [
                'id' => (string)$backup->_id,
                'timestamp' => $backup->timestamp,
                'device_name' => $backup->device_name,
            ];
        }

        return $results;
    }
}
