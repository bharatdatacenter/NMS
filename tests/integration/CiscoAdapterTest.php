<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Vendors\Cisco\CiscoAdapter;
use NMS\Vendors\Cisco\CiscoRESTCONF;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * CiscoAdapterTest
 *
 * Integration test for Cisco IOS-XE RESTCONF adapter.
 * Skipped if CISCO_HOST environment variable is not set.
 *
 * Set environment variables to run:
 *   CISCO_HOST=10.0.0.1
 *   CISCO_USER=admin
 *   CISCO_PASS=secret
 */
class CiscoAdapterTest extends TestCase
{
    private static string $host;
    private static string $user;
    private static string $pass;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('CISCO_HOST') ?: '';
        self::$user = getenv('CISCO_USER') ?: 'admin';
        self::$pass = getenv('CISCO_PASS') ?: '';
    }

    private function skip(): void
    {
        if (empty(self::$host)) {
            $this->markTestSkipped('CISCO_HOST not set — skipping Cisco integration test');
        }
    }

    public function testCiscoRESTCONFGetInterfaces(): void
    {
        $this->skip();

        $api    = new CiscoRESTCONF(self::$host, self::$user, self::$pass);
        $ifaces = $api->getInterfaces();

        $this->assertIsArray($ifaces, 'getInterfaces() must return an array');
        $this->assertNotEmpty($ifaces, 'Should have at least one interface');

        // Verify interface structure
        $first = $ifaces[0];
        $this->assertArrayHasKey('name', $first, 'Interface must have name field');
    }

    public function testCiscoRESTCONFGetRoutes(): void
    {
        $this->skip();

        $api    = new CiscoRESTCONF(self::$host, self::$user, self::$pass);
        $routes = $api->getRoutes();

        $this->assertIsArray($routes, 'getRoutes() must return an array');
    }

    public function testCiscoAdapterConnect(): void
    {
        $this->skip();

        $device  = $this->buildDeviceArray();
        $secrets = $this->buildSecretsManager();
        $redis   = $this->buildRedis();

        $adapter = new CiscoAdapter($device, $secrets, $redis);
        $result  = $adapter->connect();

        $this->assertTrue($result, 'Adapter should connect successfully');
        $this->assertTrue($adapter->isConnected());
    }

    public function testCiscoAdapterGetInterfaces(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $adapter->connect();

        $ifaces = $adapter->getInterfaces();
        $this->assertIsArray($ifaces);
        $this->assertNotEmpty($ifaces);

        foreach ($ifaces as $iface) {
            $this->assertArrayHasKey('name', $iface);
        }
    }

    public function testCiscoAdapterGetRoutes(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $adapter->connect();

        $routes = $adapter->getRoutes('ipv4');
        $this->assertIsArray($routes);

        foreach ($routes as $route) {
            $this->assertArrayHasKey('destination', $route);
        }
    }

    public function testCiscoAdapterGetSystemInfo(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $info    = $adapter->getSystemInfo();

        $this->assertIsArray($info);
    }

    public function testCiscoAdapterAllowlist(): void
    {
        $this->skip();

        $adapter  = $this->buildAdapter();
        $allowed  = $adapter->getAllowedCommands();

        $this->assertContains('show ip route', $allowed);
        $this->assertContains('show interfaces', $allowed);
        $this->assertContains('show ip bgp summary', $allowed);
        $this->assertContains('ping', $allowed);
        $this->assertContains('traceroute', $allowed);
    }

    public function testCiscoAdapterRejectsDisallowedCommand(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $this->expectException(\InvalidArgumentException::class);
        $adapter->executeCommand('write erase');
    }

    public function testCiscoAdapterDeviceFactoryRegistration(): void
    {
        $this->assertTrue(DeviceFactory::isSupported('cisco'), 'cisco must be a supported vendor');
        $this->assertContains('cisco', DeviceFactory::supportedVendors());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildDeviceArray(): array
    {
        return [
            'id'         => 'cisco-test-001',
            'vendor'     => 'cisco',
            'ip_address' => self::$host,
            'vault_path' => 'nms/devices/cisco-test-001',
        ];
    }

    private function buildSecretsManager(): AppEncryptedSecretsManager
    {
        // Use a mock that returns test credentials
        return new class(self::$user, self::$pass) extends AppEncryptedSecretsManager {
            public function __construct(private string $u, private string $p) {}

            public function get(string $path): string
            {
                return str_ends_with($path, '/username') ? $this->u : $this->p;
            }

            public function put(string $path, string $value): void {}
        };
    }

    private function buildRedis(): RedisClient
    {
        $config = require dirname(__DIR__, 2) . '/core/config/redis.php';
        return new RedisClient($config);
    }

    private function buildAdapter(): CiscoAdapter
    {
        return new CiscoAdapter(
            $this->buildDeviceArray(),
            $this->buildSecretsManager(),
            $this->buildRedis()
        );
    }
}
