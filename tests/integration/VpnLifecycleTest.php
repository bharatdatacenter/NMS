<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Database\MongoDB;
use NMS\Core\Models\VPN\VpnGatewayManager;
use NMS\Core\Models\VPN\VpnTunnelManager;
use NMS\Core\Models\VPN\VpnUserManager;
use PHPUnit\Framework\TestCase;

/**
 * VpnLifecycleTest
 *
 * Integration test: create gateway → create tunnel → check status
 * Skipped if MONGODB_URI is not set.
 *
 * Uses a mock SecretsManager so no Vault connection is required.
 */
class VpnLifecycleTest extends TestCase
{
    private static ?string $gatewayId = null;
    private static ?string $tunnelId  = null;
    private static ?string $userId    = null;

    private function buildSecretsManager(): object
    {
        return new class {
            private array $store = [];

            public function get(string $path): string
            {
                return $this->store[$path] ?? 'mock-secret-value';
            }

            public function put(string $path, string $value): void
            {
                $this->store[$path] = $value;
            }
        };
    }

    private function skip(): void
    {
        $mongoUri = getenv('MONGODB_URI') ?: getenv('MONGO_URI') ?: '';
        if (empty($mongoUri)) {
            $this->markTestSkipped('MONGODB_URI not set — skipping VPN lifecycle integration test');
        }
    }

    // ─── Gateway ──────────────────────────────────────────────────────────────

    public function testCreateGateway(): void
    {
        $this->skip();

        $secrets = $this->buildSecretsManager();
        $manager = new VpnGatewayManager($secrets);

        // Use a fake device_id (24 hex chars for ObjectId)
        $gateway = $manager->create([
            'device_id'    => '000000000000000000000001',
            'name'         => 'test-lifecycle-gateway-' . time(),
            'gateway_type' => 'ipsec',
            'public_ip'    => '85.209.161.100',
            'public_port'  => 500,
            'auth_type'    => 'psk',
            'enabled'      => true,
        ], 'test-psk-value-12345');

        self::$gatewayId = $gateway['id'];

        $this->assertNotEmpty($gateway['id']);
        $this->assertSame('ipsec', $gateway['gateway_type']);
        $this->assertSame('85.209.161.100', $gateway['public_ip']);
        $this->assertArrayHasKey('vault', $gateway, 'Response must have vault field');
        $this->assertArrayNotHasKey('psk', $gateway, 'PSK must not be in response');
        $this->assertStringStartsWith('nms/vpn/', $gateway['vault']['path'] ?? '');
    }

    public function testFindGatewayById(): void
    {
        $this->skip();

        if (self::$gatewayId === null) {
            $this->markTestSkipped('testCreateGateway must run first');
        }

        $secrets = $this->buildSecretsManager();
        $manager = new VpnGatewayManager($secrets);
        $gateway = $manager->findById(self::$gatewayId);

        $this->assertSame(self::$gatewayId, $gateway['id']);
        $this->assertSame('ipsec', $gateway['gateway_type']);
    }

    // ─── Tunnel ───────────────────────────────────────────────────────────────

    public function testCreateTunnel(): void
    {
        $this->skip();

        if (self::$gatewayId === null) {
            $this->markTestSkipped('testCreateGateway must run first');
        }

        $secrets = $this->buildSecretsManager();
        $manager = new VpnTunnelManager($secrets);

        $tunnel = $manager->create([
            'name'             => 'test-lifecycle-tunnel-' . time(),
            'local_gateway_id' => self::$gatewayId,
            'local_subnets'    => ['10.0.0.0/16'],
            'remote_gateway_ip' => '85.209.162.100',
            'remote_id'        => 'dc2-vpn@test.local',
            'remote_subnets'   => ['10.1.0.0/16'],
            'ike_version'      => '2',
            'ike_encryption'   => 'aes256',
            'ike_hash'         => 'sha256',
            'ike_dh_group'     => 14,
            'ike_lifetime'     => 86400,
            'esp_encryption'   => 'aes256',
            'esp_hash'         => 'sha256',
            'esp_pfs_group'    => 14,
            'esp_lifetime'     => 3600,
        ], 'tunnel-psk-value-67890');

        self::$tunnelId = $tunnel['id'];

        $this->assertNotEmpty($tunnel['id']);
        $this->assertSame('unknown', $tunnel['status'], 'New tunnel status must be unknown');
        $this->assertSame(self::$gatewayId, $tunnel['local_gateway_id']);
        $this->assertArrayHasKey('vault', $tunnel);
        $this->assertArrayNotHasKey('psk', $tunnel, 'PSK must not be in response');
        $this->assertFalse($tunnel['synced_to_device'], 'New tunnel must not be synced');
    }

    public function testUpdateTunnelStatus(): void
    {
        $this->skip();

        if (self::$tunnelId === null) {
            $this->markTestSkipped('testCreateTunnel must run first');
        }

        $secrets = $this->buildSecretsManager();
        $manager = new VpnTunnelManager($secrets);

        // Update to 'establishing' then to 'up'
        $manager->updateStatus(self::$tunnelId, 'establishing');
        $tunnel = $manager->findById(self::$tunnelId);
        $this->assertSame('establishing', $tunnel['status']);

        $manager->updateStatus(self::$tunnelId, 'up');
        $tunnel = $manager->findById(self::$tunnelId);
        $this->assertSame('up', $tunnel['status']);
        $this->assertNotNull($tunnel['last_status_check']);
    }

    public function testInvalidTunnelStatusRejected(): void
    {
        $this->skip();

        if (self::$tunnelId === null) {
            $this->markTestSkipped('testCreateTunnel must run first');
        }

        $secrets = $this->buildSecretsManager();
        $manager = new VpnTunnelManager($secrets);

        $this->expectException(\InvalidArgumentException::class);
        $manager->updateStatus(self::$tunnelId, 'connected');  // Not in allowed list
    }

    // ─── VPN User ─────────────────────────────────────────────────────────────

    public function testCreateVpnUser(): void
    {
        $this->skip();

        if (self::$gatewayId === null) {
            $this->markTestSkipped('testCreateGateway must run first');
        }

        $manager = new VpnUserManager();
        $user    = $manager->create([
            'gateway_id' => self::$gatewayId,
            'username'   => 'test.lifecycle.user.' . time(),
            'password'   => 'VpnUser@SecurePass123',
            'enabled'    => true,
        ], '000000000000000000000002');

        self::$userId = $user['id'];

        $this->assertNotEmpty($user['id']);
        $this->assertArrayNotHasKey('password', $user, 'Plaintext password must not be returned');
        $this->assertArrayNotHasKey('password_hash', $user, 'Hash must not be returned');
        $this->assertTrue($user['enabled']);
    }

    public function testVpnUserPasswordVerification(): void
    {
        $this->skip();

        if (self::$userId === null) {
            $this->markTestSkipped('testCreateVpnUser must run first');
        }

        $manager = new VpnUserManager();
        $correct = $manager->verifyPassword(self::$userId, 'VpnUser@SecurePass123');
        $wrong   = $manager->verifyPassword(self::$userId, 'wrong-password');

        $this->assertTrue($correct, 'Correct password must verify');
        $this->assertFalse($wrong, 'Wrong password must not verify');
    }

    public function testListVpnUsersByGateway(): void
    {
        $this->skip();

        if (self::$gatewayId === null) {
            $this->markTestSkipped('testCreateGateway must run first');
        }

        $manager = new VpnUserManager();
        $result  = $manager->list(['gateway_id' => self::$gatewayId]);

        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, $result['total']);

        // None of the users must have password_hash exposed
        foreach ($result['data'] as $user) {
            $this->assertArrayNotHasKey('password_hash', $user);
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    // ─── Cleanup ──────────────────────────────────────────────────────────────

    public static function tearDownAfterClass(): void
    {
        $mongoUri = getenv('MONGODB_URI') ?: getenv('MONGO_URI') ?: '';
        if (empty($mongoUri)) {
            return;
        }

        try {
            $db = MongoDB::getInstance()->getDatabase();

            if (self::$userId !== null) {
                (new VpnUserManager())->delete(self::$userId);
            }
            if (self::$tunnelId !== null) {
                $secrets = new class {
                    public function get(string $path): string { return ''; }
                    public function put(string $path, string $value): void {}
                };
                (new VpnTunnelManager($secrets))->delete(self::$tunnelId);
            }
            if (self::$gatewayId !== null) {
                $secrets = new class {
                    public function get(string $path): string { return ''; }
                    public function put(string $path, string $value): void {}
                };
                (new VpnGatewayManager($secrets))->delete(self::$gatewayId);
            }
        } catch (\Throwable) {
            // Best-effort cleanup
        }
    }
}
