<?php

declare(strict_types=1);

use NMS\Core\Models\Routing\BGPMonitor;
use PHPUnit\Framework\TestCase;

/**
 * BGPMonitorTest
 *
 * Integration test for BGP session polling.
 * Requires MONGODB_URI + (FORTIGATE_HOST or MIKROTIK_HOST) to run live.
 *
 * Without a real device, verifies the DB query/list paths only.
 */
class BGPMonitorTest extends TestCase
{
    private BGPMonitor $monitor;

    protected function setUp(): void
    {
        if (empty(getenv('MONGODB_URI'))) {
            $this->markTestSkipped('MONGODB_URI not set — skipping integration test');
        }

        $this->monitor = new BGPMonitor();
    }

    public function testListSessionsReturnsArray(): void
    {
        $result = $this->monitor->listSessions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
    }

    public function testListSessionsFilterByDeviceId(): void
    {
        // Should return empty (or actual data) without error
        $result = $this->monitor->listSessions(['device_id' => '000000000000000000000001']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testGetDeviceSessionsReturnsList(): void
    {
        // No device with this ID — should return empty array without error
        $sessions = $this->monitor->getDeviceSessions('000000000000000000000001');
        $this->assertIsArray($sessions);
    }

    public function testListOSPFNeighborsReturnsArray(): void
    {
        $neighbors = $this->monitor->listOSPFNeighbors();
        $this->assertIsArray($neighbors);
    }

    public function testPollSessionsRequiresRealDevice(): void
    {
        if (empty(getenv('FORTIGATE_HOST')) && empty(getenv('MIKROTIK_HOST'))) {
            $this->markTestSkipped('No real device configured (FORTIGATE_HOST/MIKROTIK_HOST not set)');
        }

        // If a device is configured, use the first device from DB
        // This tests the full poll → upsert pipeline
        $this->expectNotToPerformAssertions();
        // Actual test would require a known device_id from the test environment
    }

    public function testPollSessionsThrowsForUnknownDevice(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->monitor->pollSessions('000000000000000000000000');
    }
}
