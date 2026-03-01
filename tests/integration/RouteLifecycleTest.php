<?php

declare(strict_types=1);

use NMS\Core\Models\Routing\RouteManager;
use PHPUnit\Framework\TestCase;

/**
 * RouteLifecycleTest
 *
 * Integration test: full lifecycle for a static route in MongoDB.
 * Requires MONGODB_URI environment variable.
 *
 * Does NOT push to a real device — device sync is tested separately.
 */
class RouteLifecycleTest extends TestCase
{
    private RouteManager $manager;

    protected function setUp(): void
    {
        if (empty(getenv('MONGODB_URI'))) {
            $this->markTestSkipped('MONGODB_URI not set — skipping integration test');
        }

        $this->manager = new RouteManager();
    }

    public function testCreateAndFindRoute(): void
    {
        $routeId = $this->manager->create([
            'ip_version'   => 'ipv4',
            'destination'  => '203.0.113.100/32',
            'gateway'      => '10.0.0.1',
            'interface_name' => 'port2',
            'distance'     => 1,
            'metric'       => 0,
            'route_type'   => 'host',
            'device_id'    => '000000000000000000000001',
            'purpose'      => 'Test route lifecycle',
        ], '000000000000000000000002');

        $this->assertNotEmpty($routeId);

        $route = $this->manager->findById($routeId);
        $this->assertNotNull($route);
        $this->assertSame('203.0.113.100/32', $route['destination']);
        $this->assertSame('ipv4', $route['ip_version']);
        $this->assertSame('10.0.0.1', $route['gateway']);
        $this->assertFalse($route['synced_to_device']);

        // Cleanup
        $this->manager->delete($routeId, '000000000000000000000002');
        $this->assertNull($this->manager->findById($routeId));
    }

    public function testCreateIPv6Route(): void
    {
        $routeId = $this->manager->create([
            'ip_version'  => 'ipv6',
            'destination' => '2001:db8::100/128',
            'gateway'     => '2001:db8::1',
            'route_type'  => 'host',
            'device_id'   => '000000000000000000000001',
        ], '000000000000000000000002');

        $route = $this->manager->findById($routeId);
        $this->assertSame('ipv6', $route['ip_version']);
        $this->assertSame('2001:db8::100/128', $route['destination']);

        $this->manager->delete($routeId, '000000000000000000000002');
    }

    public function testSyncStatusUpdate(): void
    {
        $routeId = $this->manager->create([
            'ip_version'  => 'ipv4',
            'destination' => '198.51.100.50/32',
            'gateway'     => '10.0.0.1',
            'device_id'   => '000000000000000000000001',
        ], '000000000000000000000002');

        $this->manager->updateSyncStatus($routeId, true, '*A1', null);

        $route = $this->manager->findById($routeId);
        $this->assertTrue($route['synced_to_device']);
        $this->assertSame('*A1', $route['device_route_id']);
        $this->assertNull($route['sync_error']);

        $this->manager->delete($routeId, '000000000000000000000002');
    }

    public function testDeleteWritesHistory(): void
    {
        $routeId = $this->manager->create([
            'ip_version'  => 'ipv4',
            'destination' => '192.0.2.1/32',
            'gateway'     => '10.0.0.1',
            'device_id'   => '000000000000000000000001',
            'purpose'     => 'Delete history test',
        ], '000000000000000000000002');

        $this->manager->delete($routeId, '000000000000000000000002');

        // Route should be gone
        $this->assertNull($this->manager->findById($routeId));
    }

    public function testListFiltersById(): void
    {
        $deviceId = '000000000000000000000099';

        $id1 = $this->manager->create([
            'ip_version'  => 'ipv4',
            'destination' => '10.10.10.1/32',
            'gateway'     => '10.0.0.1',
            'device_id'   => $deviceId,
        ], '000000000000000000000002');

        $result = $this->manager->list(['device_id' => $deviceId]);
        $this->assertGreaterThanOrEqual(1, $result['total']);

        $this->manager->delete($id1, '000000000000000000000002');
    }
}
