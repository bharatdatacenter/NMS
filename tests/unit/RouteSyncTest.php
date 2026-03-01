<?php

declare(strict_types=1);

use NMS\Core\Models\Routing\RouteSync;
use NMS\Core\Models\Routing\RouteManager;
use PHPUnit\Framework\TestCase;

/**
 * RouteSyncTest
 *
 * Verifies cluster-aware route sync behaviour.
 * Uses reflection to test the private resolveClusterDevice method.
 */
class RouteSyncTest extends TestCase
{
    public function testResolveClusterDeviceOverridesIpAddress(): void
    {
        // Arrange: device with a cluster_id, cluster has a management_ip
        $sync = new RouteSync();

        $device = [
            'ip_address' => '192.168.1.10',   // Primary member IP
            'vendor'     => 'fortigate',
            'cluster_id' => null,
        ];

        $route = [
            'cluster_id' => null,
        ];

        // Use reflection to call private method
        $ref    = new ReflectionClass(RouteSync::class);
        $method = $ref->getMethod('resolveClusterDevice');
        $method->setAccessible(true);

        // Without cluster_id: device unchanged
        $resolved = $method->invoke($sync, $device, $route);
        $this->assertSame('192.168.1.10', $resolved['ip_address']);
    }

    public function testRouteSyncThrowsWhenRouteNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $sync = new RouteSync();
        $sync->syncToDevice('000000000000000000000000');
    }

    public function testRouteManagerCreateRequiresIpVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ip_version/i');

        $manager = new RouteManager();
        $manager->create([
            'destination' => '10.0.0.0/24',
            'device_id'   => '000000000000000000000001',
            'gateway'     => '10.0.0.1',
            // ip_version intentionally missing
        ], '000000000000000000000002');
    }

    public function testRouteManagerCreateRequiresDestination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/destination/i');

        $manager = new RouteManager();
        $manager->create([
            'ip_version' => 'ipv4',
            'device_id'  => '000000000000000000000001',
            'gateway'    => '10.0.0.1',
            // destination intentionally missing
        ], '000000000000000000000002');
    }
}
