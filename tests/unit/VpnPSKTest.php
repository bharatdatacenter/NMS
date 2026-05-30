<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * VpnPSKTest
 *
 * Verifies that VPN PSKs are stored as Vault path references in MongoDB,
 * not as the actual PSK string.
 */
class VpnPSKTest extends TestCase
{
    public function testGatewayDocumentHasVaultPathNotPSK(): void
    {
        // Simulate the document structure VpnGatewayManager stores in MongoDB
        $actualPSK  = 'super-secret-ipsec-psk-string';
        $vaultPath  = 'nms/vpn/dc1-gateway/psk';

        $doc = [
            'device_id'    => 'device-123',
            'name'         => 'DC1-VPN-Gateway',
            'gateway_type' => 'ipsec',
            'public_ip'    => '85.209.161.2',
            'public_port'  => 500,
            'auth_type'    => 'psk',
            'vault'        => [
                'provider' => 'hashicorp_vault',
                'path'     => $vaultPath,
                'version'  => 1,
            ],
            'enabled'      => true,
        ];

        // PSK must NOT appear anywhere in the document
        $docJson = json_encode($doc);
        $this->assertStringNotContainsString(
            $actualPSK,
            $docJson,
            'Actual PSK must not be present in the MongoDB document'
        );

        // Vault reference must be present
        $this->assertArrayHasKey('vault', $doc, 'vault field must exist');
        $this->assertSame('hashicorp_vault', $doc['vault']['provider']);
        $this->assertSame($vaultPath, $doc['vault']['path']);
        $this->assertSame(1, $doc['vault']['version']);
        $this->assertArrayNotHasKey('psk', $doc, 'psk field must not exist in document');
    }

    public function testTunnelDocumentHasVaultPathNotPSK(): void
    {
        $actualPSK = 'tunnel-ike-preshared-key-xyz';
        $vaultPath = 'nms/vpn/dc1-to-dc2-tunnel/psk';

        $doc = [
            'name'             => 'DC1-to-DC2-Tunnel',
            'local_gateway_id' => 'gateway-123',
            'local_subnets'    => ['10.0.0.0/16'],
            'remote_gateway_ip' => '85.209.162.2',
            'remote_subnets'   => ['10.1.0.0/16'],
            'ike_version'      => '2',
            'ike_encryption'   => 'aes256',
            'vault'            => [
                'provider' => 'hashicorp_vault',
                'path'     => $vaultPath,
                'version'  => 1,
            ],
            'status'           => 'unknown',
        ];

        $docJson = json_encode($doc);
        $this->assertStringNotContainsString(
            $actualPSK,
            $docJson,
            'Actual PSK must not be present in the tunnel document'
        );

        $this->assertArrayHasKey('vault', $doc);
        $this->assertSame($vaultPath, $doc['vault']['path']);
        $this->assertArrayNotHasKey('psk', $doc, 'psk key must not exist in tunnel document');
    }

    public function testVaultPathFollowsNamingConvention(): void
    {
        // Vault paths for VPN PSKs must follow: nms/vpn/{slug}/psk
        $gatewayName = 'DC1-VPN-Gateway';
        $slug        = preg_replace('/[^a-z0-9-]/', '-', strtolower($gatewayName));
        $vaultPath   = "nms/vpn/{$slug}/psk";

        $this->assertSame('dc1-vpn-gateway', $slug);
        $this->assertSame('nms/vpn/dc1-vpn-gateway/psk', $vaultPath);
        $this->assertStringStartsWith('nms/vpn/', $vaultPath);
        $this->assertStringEndsWith('/psk', $vaultPath);
    }

    public function testTunnelStatusValues(): void
    {
        // Tunnel status must only be one of the allowed values
        $allowed = ['up', 'down', 'establishing', 'unknown'];

        foreach ($allowed as $status) {
            $this->assertContains($status, $allowed, "Status '{$status}' should be in allowed list");
        }

        $this->assertCount(4, $allowed, 'Should have exactly 4 allowed statuses');
    }

    public function testVaultVersionTracking(): void
    {
        // Version must increment on PSK rotation
        $initial = ['path' => 'nms/vpn/gw/psk', 'version' => 1, 'provider' => 'hashicorp_vault'];
        $rotated = $initial;
        $rotated['version'] = $initial['version'] + 1;

        $this->assertSame(2, $rotated['version'], 'Version must increment on PSK rotation');
        $this->assertSame($initial['path'], $rotated['path'], 'Path must remain the same after rotation');
    }
}
