<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\FortiGate\FortiGateAdapter;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use NMS\Vendors\VyOS\VyOSAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * DeviceFactoryTest
 *
 * Verifies that DeviceFactory returns the correct adapter class for each vendor.
 * Uses mock Redis and Secrets to avoid real connections.
 */
class DeviceFactoryTest extends TestCase
{
    private SecretsManagerInterface $secrets;
    private RedisClient $redis;

    protected function setUp(): void
    {
        // Mock SecretsManager — returns predictable values for any path
        $this->secrets = new class implements SecretsManagerInterface {
            public function get(string $path): string   { return 'test-value'; }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool  { return true; }
        };

        // Mock Redis — no real connection
        $this->redis = $this->createMock(RedisClient::class);
        $this->redis->method('get')->willReturn(null);
        $this->redis->method('set')->willReturn(null);
    }

    public function testMikroTikVendorReturnsMikroTikAdapter(): void
    {
        $device  = $this->makeDevice('mikrotik', '192.168.1.1');
        $adapter = DeviceFactory::create($device, $this->secrets, $this->redis);

        $this->assertInstanceOf(MikroTikAdapter::class, $adapter);
    }

    public function testFortiGateVendorReturnsFortiGateAdapter(): void
    {
        $device  = $this->makeDevice('fortigate', '10.0.0.1');
        $adapter = DeviceFactory::create($device, $this->secrets, $this->redis);

        $this->assertInstanceOf(FortiGateAdapter::class, $adapter);
    }

    public function testVyOSVendorReturnsVyOSAdapter(): void
    {
        $device  = $this->makeDevice('vyos', '10.0.0.2');
        $adapter = DeviceFactory::create($device, $this->secrets, $this->redis);

        $this->assertInstanceOf(VyOSAdapter::class, $adapter);
    }

    public function testUnknownVendorReturnsNull(): void
    {
        $device  = $this->makeDevice('cisco', '10.0.0.3');
        $adapter = DeviceFactory::create($device, $this->secrets, $this->redis);

        $this->assertNull($adapter);
    }

    public function testEmptyVendorReturnsNull(): void
    {
        $device  = $this->makeDevice('', '10.0.0.4');
        $adapter = DeviceFactory::create($device, $this->secrets, $this->redis);

        $this->assertNull($adapter);
    }

    public function testIsSupportedReturnsTrueForKnownVendors(): void
    {
        $this->assertTrue(DeviceFactory::isSupported('mikrotik'));
        $this->assertTrue(DeviceFactory::isSupported('fortigate'));
        $this->assertTrue(DeviceFactory::isSupported('vyos'));
    }

    public function testIsSupportedReturnsFalseForUnknownVendors(): void
    {
        $this->assertFalse(DeviceFactory::isSupported('cisco'));
        $this->assertFalse(DeviceFactory::isSupported('aruba'));
        $this->assertFalse(DeviceFactory::isSupported(''));
        $this->assertFalse(DeviceFactory::isSupported('juniper'));
    }

    public function testSupportedVendorsListIsComplete(): void
    {
        $vendors = DeviceFactory::supportedVendors();
        $this->assertContains('mikrotik',  $vendors);
        $this->assertContains('fortigate', $vendors);
        $this->assertContains('vyos',      $vendors);
        $this->assertCount(3, $vendors);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeDevice(string $vendor, string $ip): array
    {
        return [
            'id'          => '507f1f77bcf86cd799439011',
            'vendor'      => $vendor,
            'ip_address'  => $ip,
            'vault_path'  => 'nms/devices/test',
            'name'        => 'Test Device',
        ];
    }
}
