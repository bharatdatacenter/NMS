<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * MikroTikAdapterTest — Integration test for MikroTik vendor adapter.
 *
 * Connects to a real MikroTik device if env vars are set.
 * Skipped automatically in CI/CD unless MIKROTIK_HOST is configured.
 *
 * Required environment variables:
 *   MIKROTIK_HOST     — device IP or hostname
 *   MIKROTIK_USER     — API username
 *   MIKROTIK_PASS     — API password
 *
 * The test validates the full adapter contract:
 *   - getInterfaces()    — returns array of interface objects
 *   - getRoutes()        — returns array of route objects
 *   - getNeighborTable() — returns array of ARP entries
 *   - getAllowedCommands() — returns the static allowlist
 */
class MikroTikAdapterTest extends TestCase
{
    private MikroTikAdapter $adapter;

    protected function setUp(): void
    {
        $host = getenv('MIKROTIK_HOST');
        $user = getenv('MIKROTIK_USER');
        $pass = getenv('MIKROTIK_PASS');

        if (!$host || !$user || !$pass) {
            $this->markTestSkipped(
                'MikroTik integration test skipped. Set MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS env vars.'
            );
        }

        $secrets = new class($user, $pass) implements SecretsManagerInterface {
            public function __construct(private string $user, private string $pass) {}
            public function get(string $path): string {
                return str_ends_with($path, '/username') ? $this->user : $this->pass;
            }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool { return true; }
        };

        $redis   = new RedisClient(require dirname(__DIR__, 2) . '/core/config/redis.php');
        $device  = [
            'id'         => '507f1f77bcf86cd799439011',
            'ip_address' => $host,
            'vault_path' => 'nms/devices/test',
            'vendor'     => 'mikrotik',
        ];

        $this->adapter = new MikroTikAdapter($device, $secrets, $redis);
    }

    public function testConnect(): void
    {
        $result = $this->adapter->connect();
        $this->assertTrue($result, 'connect() should return true when device is reachable');
    }

    public function testGetInterfaces(): void
    {
        $this->adapter->connect();
        $interfaces = $this->adapter->getInterfaces();

        $this->assertIsArray($interfaces);
        $this->assertNotEmpty($interfaces, 'Device must have at least one interface');

        $first = $interfaces[0];
        $this->assertArrayHasKey('name',    $first);
        $this->assertArrayHasKey('type',    $first);
        $this->assertArrayHasKey('running', $first);
        $this->assertIsBool($first['running']);
    }

    public function testGetRoutesIPv4(): void
    {
        $this->adapter->connect();
        $routes = $this->adapter->getRoutes('ipv4');

        $this->assertIsArray($routes);
        $this->assertNotEmpty($routes, 'Device must have at least one route (default route)');

        $first = $routes[0];
        $this->assertArrayHasKey('destination', $first);
        $this->assertArrayHasKey('gateway',     $first);
        $this->assertArrayHasKey('active',      $first);
    }

    public function testGetNeighborTable(): void
    {
        $this->adapter->connect();
        $arp = $this->adapter->getNeighborTable('arp');

        $this->assertIsArray($arp);
        // ARP table may be empty on an isolated test device — just verify structure
        foreach ($arp as $entry) {
            $this->assertArrayHasKey('ip',  $entry);
            $this->assertArrayHasKey('mac', $entry);
        }
    }

    public function testGetSystemInfo(): void
    {
        $this->adapter->connect();
        $info = $this->adapter->getSystemInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('version', $info);
        $this->assertArrayHasKey('cpu_load', $info);
    }

    public function testGetAllowedCommands(): void
    {
        $commands = $this->adapter->getAllowedCommands();
        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);
        $this->assertContains('ping', $commands);
    }
}
