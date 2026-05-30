<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Vendors\Aruba\ArubaAdapter;
use NMS\Vendors\Aruba\ArubaAPI;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * ArubaAdapterTest
 *
 * Integration test for Aruba CX REST adapter.
 * Skipped if ARUBA_HOST environment variable is not set.
 *
 * Set environment variables to run:
 *   ARUBA_HOST=10.0.0.2
 *   ARUBA_USER=admin
 *   ARUBA_PASS=secret
 */
class ArubaAdapterTest extends TestCase
{
    private static string $host;
    private static string $user;
    private static string $pass;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('ARUBA_HOST') ?: '';
        self::$user = getenv('ARUBA_USER') ?: 'admin';
        self::$pass = getenv('ARUBA_PASS') ?: '';
    }

    private function skip(): void
    {
        if (empty(self::$host)) {
            $this->markTestSkipped('ARUBA_HOST not set — skipping Aruba integration test');
        }
    }

    public function testArubaAPILogin(): void
    {
        $this->skip();

        $api = new ArubaAPI(self::$host, self::$user, self::$pass);
        $api->login();

        $this->assertTrue($api->isLoggedIn(), 'Should be logged in after login()');

        $api->logout();
        $this->assertFalse($api->isLoggedIn(), 'Should not be logged in after logout()');
    }

    public function testArubaAPIGetVLANs(): void
    {
        $this->skip();

        $api = new ArubaAPI(self::$host, self::$user, self::$pass);
        $api->login();

        $vlans = $api->getVLANs();
        $this->assertIsArray($vlans, 'getVLANs() must return an array');

        $api->logout();
    }

    public function testArubaAPIGetInterfaces(): void
    {
        $this->skip();

        $api = new ArubaAPI(self::$host, self::$user, self::$pass);
        $ifaces = $api->getInterfaces();

        $this->assertIsArray($ifaces);
        $this->assertNotEmpty($ifaces);
    }

    public function testArubaAdapterConnect(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $result  = $adapter->connect();

        $this->assertTrue($result, 'Adapter should connect successfully');
        $this->assertTrue($adapter->isConnected());

        $adapter->disconnect();
        $this->assertFalse($adapter->isConnected());
    }

    public function testArubaAdapterGetInterfaces(): void
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

        $adapter->disconnect();
    }

    public function testArubaAdapterGetRoutes(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $routes  = $adapter->getRoutes('ipv4');

        $this->assertIsArray($routes);
    }

    public function testArubaAdapterGetVLANs(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $adapter->connect();

        // VLANs accessible via executeCommand('show vlan')
        $result = $adapter->executeCommand('show vlan');
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
    }

    public function testArubaAdapterAllowlist(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $allowed = $adapter->getAllowedCommands();

        $this->assertContains('show interfaces', $allowed);
        $this->assertContains('show ip route', $allowed);
        $this->assertContains('show vlan', $allowed);
        $this->assertContains('ping', $allowed);
        $this->assertContains('traceroute', $allowed);
    }

    public function testArubaAdapterRejectsDisallowedCommand(): void
    {
        $this->skip();

        $adapter = $this->buildAdapter();
        $this->expectException(\InvalidArgumentException::class);
        $adapter->executeCommand('erase startup-config');
    }

    public function testArubaAdapterDeviceFactoryRegistration(): void
    {
        $this->assertTrue(DeviceFactory::isSupported('aruba'), 'aruba must be a supported vendor');
        $this->assertContains('aruba', DeviceFactory::supportedVendors());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildDeviceArray(): array
    {
        return [
            'id'         => 'aruba-test-001',
            'vendor'     => 'aruba',
            'ip_address' => self::$host,
            'vault_path' => 'nms/devices/aruba-test-001',
        ];
    }

    private function buildSecretsManager(): AppEncryptedSecretsManager
    {
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

    private function buildAdapter(): ArubaAdapter
    {
        return new ArubaAdapter(
            $this->buildDeviceArray(),
            $this->buildSecretsManager(),
            $this->buildRedis()
        );
    }
}
