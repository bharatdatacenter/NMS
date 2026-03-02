<?php

declare(strict_types=1);

namespace NMS\Services\Scheduler;

/**
 * DriftScanner: Periodic drift detection runner
 *
 * Stub for Phase 7. Full implementation in Phase 8 (Monitoring + Drift Detection).
 *
 * Will:
 * - Poll all devices per role interval (edge: 5min, core: 10min, access: 30min)
 * - Compare device config with NMS expected state
 * - Detect drift and create drift records
 * - Create IMS tickets for new drift if requires_approval
 */
class DriftScanner
{
    /**
     * Scan all devices for drift
     *
     * @return array Summary {scanned, drifted}
     */
    public function scanAll(): array
    {
        // TODO Phase 8
        return [
            'scanned' => 0,
            'drifted' => 0,
        ];
    }

    /**
     * Scan a specific device for drift
     *
     * @param string $deviceId
     * @return array Drift results
     */
    public function scanDevice(string $deviceId): array
    {
        // TODO Phase 8
        return [];
    }
}
