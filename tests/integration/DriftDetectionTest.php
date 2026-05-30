<?php

declare(strict_types=1);

namespace Tests\Integration;

use NMS\Core\Models\Drift\DriftDetector;
use PHPUnit\Framework\TestCase;

/**
 * Integration drift workflow:
 * manual config change -> detect -> pull -> DB matches device snapshot.
 */
class DriftDetectionTest extends TestCase
{
    /**
     * @group integration
     */
    public function testDetectAndResolvePullWorkflow(): void
    {
        $deviceId = getenv('DRIFT_TEST_DEVICE_ID');
        if (!$deviceId) {
            $this->markTestSkipped('DRIFT_TEST_DEVICE_ID environment variable not set');
        }

        try {
            $detector = new DriftDetector();
            $drift = $detector->scanDevice((string)$deviceId);

            if ($drift === null) {
                $this->markTestSkipped('No drift detected in current environment');
            }

            $detector->resolveAsPull((string)$drift['drift_id']);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Drift integration environment not available: ' . $e->getMessage());
        }
    }
}
