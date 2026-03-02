<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

use NMS\Core\Models\Routing\BGPMonitor;
use NMS\Core\Database\Collection;

/**
 * BGPPoller: Periodic BGP session polling
 *
 * Calls BGPMonitor::pollSessions() periodically to update BGP session state
 */
class BGPPoller
{
    private BGPMonitor $bgpMonitor;
    private Collection $devicesCollection;

    public function __construct()
    {
        $this->bgpMonitor = new BGPMonitor();
        $this->devicesCollection = new Collection('devices');
    }

    /**
     * Poll BGP sessions from all devices that support BGP
     *
     * @return array Summary {polled, updated, failed}
     */
    public function pollAllSessions(): array
    {
        // Get devices that have BGP enabled
        $devices = $this->devicesCollection->find(
            ['bgp_enabled' => true],
            ['limit' => 100]
        );

        $results = [
            'polled' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        foreach ($devices as $device) {
            try {
                $this->pollDeviceBGP($device);
                $results['polled']++;
                $results['updated']++;
            } catch (\Exception $e) {
                $results['polled']++;
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Poll BGP sessions from a specific device
     *
     * @param object $device
     * @throws \Exception
     */
    private function pollDeviceBGP($device): void
    {
        // Call BGPMonitor::pollSessions() for this device
        // This updates bgp_sessions collection
        $this->bgpMonitor->pollSessions((string)$device->_id);
    }

    /**
     * Get BGP session summary
     *
     * @return array Summary {total_sessions, up, down, total_prefixes}
     */
    public function getSessionsSummary(): array
    {
        $bgpSessionsCollection = new Collection('bgp_sessions');

        $sessions = $bgpSessionsCollection->find(['status' => ['$ne' => 'inactive']]);

        $summary = [
            'total_sessions' => 0,
            'up' => 0,
            'down' => 0,
            'total_prefixes' => 0,
        ];

        foreach ($sessions as $session) {
            $summary['total_sessions']++;
            if ($session->status === 'established') {
                $summary['up']++;
            } else {
                $summary['down']++;
            }
            $summary['total_prefixes'] += count($session->prefixes ?? []);
        }

        return $summary;
    }
}
