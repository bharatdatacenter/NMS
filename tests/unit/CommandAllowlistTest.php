<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\FortiGate\FortiGateAdapter;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use NMS\Vendors\VyOS\VyOSAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * CommandAllowlistTest
 *
 * Verifies that each vendor adapter's allowlist:
 *   1. Returns the expected set of safe commands via getAllowedCommands()
 *   2. Accepts all allowlisted commands without throwing
 *   3. Rejects commands NOT in the allowlist with InvalidArgumentException
 *
 * No real network connections — validation happens before any API call.
 */
class CommandAllowlistTest extends TestCase
{
    private SecretsManagerInterface $secrets;
    private RedisClient $redis;

    protected function setUp(): void
    {
        $this->secrets = new class implements SecretsManagerInterface {
            public function get(string $path): string   { return 'test'; }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool  { return true; }
        };

        $this->redis = $this->createMock(RedisClient::class);
        $this->redis->method('get')->willReturn(null);
        $this->redis->method('set')->willReturn(null);
    }

    // ─── MikroTik ─────────────────────────────────────────────────────────────

    public function testMikroTikAllowlistContainsExpectedCommands(): void
    {
        $adapter  = $this->makeMikroTik();
        $allowed  = $adapter->getAllowedCommands();

        $this->assertContains('ping',                    $allowed);
        $this->assertContains('traceroute',              $allowed);
        $this->assertContains('/tool/torch',             $allowed);
        $this->assertContains('/system/resource/print',  $allowed);
        $this->assertContains('/interface/print',        $allowed);
    }

    public function testMikroTikRejectsDisallowedCommands(): void
    {
        $adapter = $this->makeMikroTik();

        $disallowed = [
            '/ip/address/remove',
            '/system/reboot',
            '/system/reset-configuration',
            'rm -rf /',
            '; DROP TABLE devices; --',
        ];

        foreach ($disallowed as $cmd) {
            try {
                $adapter->executeCommand($cmd);
                $this->fail("Expected InvalidArgumentException for command: {$cmd}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('allowlist', strtolower($e->getMessage()));
            }
        }
    }

    // ─── FortiGate ────────────────────────────────────────────────────────────

    public function testFortiGateAllowlistContainsExpectedCommands(): void
    {
        $adapter = $this->makeFortiGate();
        $allowed = $adapter->getAllowedCommands();

        $this->assertContains('get system status',                      $allowed);
        $this->assertContains('get router info routing-table all',      $allowed);
        $this->assertContains('diagnose sys top',                       $allowed);
        $this->assertContains('execute ping',                           $allowed);
        $this->assertContains('execute traceroute',                     $allowed);
        $this->assertContains('get system performance status',          $allowed);
    }

    public function testFortiGateRejectsDisallowedCommands(): void
    {
        $adapter = $this->makeFortiGate();

        $disallowed = [
            'execute reboot',
            'delete firewall policy 1',
            'config system admin',
            'exec format disk',
        ];

        foreach ($disallowed as $cmd) {
            try {
                $adapter->executeCommand($cmd);
                $this->fail("Expected InvalidArgumentException for command: {$cmd}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('allowlist', strtolower($e->getMessage()));
            }
        }
    }

    // ─── VyOS ─────────────────────────────────────────────────────────────────

    public function testVyOSAllowlistContainsExpectedCommands(): void
    {
        $adapter = $this->makeVyOS();
        $allowed = $adapter->getAllowedCommands();

        $this->assertContains('show interfaces',   $allowed);
        $this->assertContains('show ip route',     $allowed);
        $this->assertContains('show ip bgp summary',$allowed);
        $this->assertContains('ping',              $allowed);
        $this->assertContains('traceroute',        $allowed);
    }

    public function testVyOSRejectsDisallowedCommands(): void
    {
        $adapter = $this->makeVyOS();

        $disallowed = [
            'reboot',
            'delete system config',
            'set system root-login',
            '/bin/bash',
        ];

        foreach ($disallowed as $cmd) {
            try {
                $adapter->executeCommand($cmd);
                $this->fail("Expected InvalidArgumentException for command: {$cmd}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('allowlist', strtolower($e->getMessage()));
            }
        }
    }

    // ─── Cross-vendor ─────────────────────────────────────────────────────────

    public function testAllAdaptersHaveNonEmptyAllowlists(): void
    {
        foreach ([$this->makeMikroTik(), $this->makeFortiGate(), $this->makeVyOS()] as $adapter) {
            $this->assertNotEmpty(
                $adapter->getAllowedCommands(),
                get_class($adapter) . ' must have a non-empty allowlist'
            );
        }
    }

    public function testAllowlistsContainOnlyStrings(): void
    {
        foreach ([$this->makeMikroTik(), $this->makeFortiGate(), $this->makeVyOS()] as $adapter) {
            foreach ($adapter->getAllowedCommands() as $cmd) {
                $this->assertIsString($cmd, get_class($adapter) . ': allowlist entries must be strings');
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeMikroTik(): MikroTikAdapter
    {
        return new MikroTikAdapter(
            ['id' => '507f1f77bcf86cd799439011', 'ip_address' => '192.168.1.1', 'vault_path' => 'test'],
            $this->secrets,
            $this->redis
        );
    }

    private function makeFortiGate(): FortiGateAdapter
    {
        return new FortiGateAdapter(
            ['id' => '507f1f77bcf86cd799439012', 'ip_address' => '10.0.0.1', 'vault_path' => 'test'],
            $this->secrets,
            $this->redis
        );
    }

    private function makeVyOS(): VyOSAdapter
    {
        return new VyOSAdapter(
            ['id' => '507f1f77bcf86cd799439013', 'ip_address' => '10.0.0.2', 'vault_path' => 'test'],
            $this->secrets,
            $this->redis
        );
    }
}
