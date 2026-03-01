<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\FortiGate\FortiGateAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * FortiGateAdapterTest — Integration test for FortiGate vendor adapter.
 *
 * Connects to a real FortiGate device if env vars are set.
 * Skipped automatically in CI/CD unless FORTIGATE_HOST is configured.
 *
 * Required environment variables:
 *   FORTIGATE_HOST      — device IP or hostname
 *   FORTIGATE_API_TOKEN — API token (Bearer)
 *
 * The test validates:
 *   - getFirewallRules() — returns IPv4 and IPv6 policies
 *   - getHAStatus()      — returns cluster state (even if 'none' on standalone)
 *   - getInterfaces()    — returns interface list
 *   - getRoutes()        — returns static routes
 */
class FortiGateAdapterTest extends TestCase
{
    private FortiGateAdapter $adapter;

    protected function setUp(): void
    {
        $host  = getenv('FORTIGATE_HOST');
        $token = getenv('FORTIGATE_API_TOKEN');

        if (!$host || !$token) {
            $this->markTestSkipped(
                'FortiGate integration test skipped. Set FORTIGATE_HOST, FORTIGATE_API_TOKEN env vars.'
            );
        }

        $secrets = new class($token) implements SecretsManagerInterface {
            public function __construct(private string $token) {}
            public function get(string $path): string {
                return $this->token;
            }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool { return true; }
        };

        $redis  = new RedisClient(require dirname(__DIR__, 2) . '/core/config/redis.php');
        $device = [
            'id'         => '507f1f77bcf86cd799439020',
            'ip_address' => $host,
            'vault_path' => 'nms/devices/fortigate-test',
            'vendor'     => 'fortigate',
        ];

        $this->adapter = new FortiGateAdapter($device, $secrets, $redis);
    }

    public function testConnect(): void
    {
        $result = $this->adapter->connect();
        $this->assertTrue($result, 'connect() should return true when device is reachable');
    }

    public function testGetFirewallRules(): void
    {
        $this->adapter->connect();
        $rules = $this->adapter->getFirewallRules();

        $this->assertIsArray($rules);
        // FortiGate always has at least the implicit deny
        foreach ($rules as $rule) {
            $this->assertArrayHasKey('id',         $rule);
            $this->assertArrayHasKey('action',     $rule);
            $this->assertArrayHasKey('ip_version', $rule);
            $this->assertContains($rule['ip_version'], ['ipv4', 'ipv6']);
        }
    }

    public function testGetHAStatus(): void
    {
        $this->adapter->connect();
        $ha = $this->adapter->getHAStatus();

        $this->assertIsArray($ha);
        $this->assertArrayHasKey('type', $ha);
        $this->assertArrayHasKey('members', $ha);
        $this->assertIsArray($ha['members']);
        // Standalone FortiGate may have 0 HA peers
    }

    public function testGetInterfaces(): void
    {
        $this->adapter->connect();
        $interfaces = $this->adapter->getInterfaces();

        $this->assertIsArray($interfaces);
        $this->assertNotEmpty($interfaces);

        $first = $interfaces[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('type', $first);
    }

    public function testGetRoutes(): void
    {
        $this->adapter->connect();
        $routes = $this->adapter->getRoutes();

        $this->assertIsArray($routes);
        foreach ($routes as $route) {
            $this->assertArrayHasKey('destination', $route);
            $this->assertArrayHasKey('gateway', $route);
        }
    }

    public function testGetSystemInfo(): void
    {
        $this->adapter->connect();
        $info = $this->adapter->getSystemInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('hostname', $info);
    }

    public function testGetBGPSessions(): void
    {
        $this->adapter->connect();
        $sessions = $this->adapter->getBGPSessions();

        // May be empty if BGP is not configured
        $this->assertIsArray($sessions);
        foreach ($sessions as $session) {
            $this->assertArrayHasKey('remote_address', $session);
            $this->assertArrayHasKey('state',          $session);
            $this->assertArrayHasKey('established',    $session);
            $this->assertIsBool($session['established']);
        }
    }
}
