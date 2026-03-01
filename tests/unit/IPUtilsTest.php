<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Helpers\IPUtils;

class IPUtilsTest extends TestCase
{
    // ─── Detection ───────────────────────────────────────────────────────────

    public function testDetectIPv4(): void
    {
        $this->assertEquals(4, IPUtils::detectVersion('192.168.1.1'));
        $this->assertEquals(4, IPUtils::detectVersion('10.0.0.1'));
        $this->assertEquals(4, IPUtils::detectVersion('255.255.255.255'));
    }

    public function testDetectIPv6(): void
    {
        $this->assertEquals(6, IPUtils::detectVersion('::1'));
        $this->assertEquals(6, IPUtils::detectVersion('2001:db8::1'));
        $this->assertEquals(6, IPUtils::detectVersion('fe80::1'));
    }

    public function testIsIPv4(): void
    {
        $this->assertTrue(IPUtils::isIPv4('192.168.1.1'));
        $this->assertFalse(IPUtils::isIPv4('::1'));
        $this->assertFalse(IPUtils::isIPv4('invalid'));
    }

    public function testIsIPv6(): void
    {
        $this->assertTrue(IPUtils::isIPv6('::1'));
        $this->assertFalse(IPUtils::isIPv6('192.168.1.1'));
    }

    public function testInvalidIPThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IPUtils::detectVersion('not-an-ip');
    }

    // ─── IPv4 CIDR parsing ───────────────────────────────────────────────────

    public function testParseCIDRIPv4(): void
    {
        $result = IPUtils::parseCIDR('192.168.1.0/24');
        $this->assertEquals(4, $result['version']);
        $this->assertEquals('192.168.1.0', $result['network']);
        $this->assertEquals('192.168.1.255', $result['broadcast']);
        $this->assertEquals(24, $result['prefix']);
    }

    public function testParseCIDRIPv6(): void
    {
        $result = IPUtils::parseCIDR('2001:db8::/32');
        $this->assertEquals(6, $result['version']);
        $this->assertEquals(32, $result['prefix']);
        $this->assertArrayHasKey('network', $result);
    }

    // ─── IPv4 usable range ───────────────────────────────────────────────────

    public function testIPv4UsableRange(): void
    {
        $range = IPUtils::getUsableRange('192.168.1.0/24');
        $this->assertEquals('192.168.1.1', $range['first']);
        $this->assertEquals('192.168.1.254', $range['last']);
        $this->assertEquals(254, $range['total']);
    }

    public function testIPv4SlashThirtyTwo(): void
    {
        $range = IPUtils::getUsableRange('10.0.0.1/32');
        $this->assertEquals('10.0.0.1', $range['first']);
        $this->assertEquals('10.0.0.1', $range['last']);
        $this->assertEquals(1, $range['total']);
    }

    public function testIPv4SlashThirtyOne(): void
    {
        $range = IPUtils::getUsableRange('10.0.0.0/31');
        $this->assertEquals(2, $range['total']);
    }

    // ─── IPv6 usable range (all addresses usable) ────────────────────────────

    public function testIPv6AllAddressesUsable(): void
    {
        $range = IPUtils::getUsableRange('2001:db8::/126');
        // /126 = 4 addresses, all usable
        $this->assertEquals(4, $range['total']);
        $this->assertEquals('2001:db8::', $range['first']);
    }

    public function testIPv6SlashOneTwentyEight(): void
    {
        $range = IPUtils::getUsableRange('2001:db8::1/128');
        $this->assertEquals(1, $range['total']);
        $this->assertEquals('2001:db8::1', $range['first']);
        $this->assertEquals('2001:db8::1', $range['last']);
    }

    // ─── networkContains ─────────────────────────────────────────────────────

    public function testNetworkContainsIPv4(): void
    {
        $this->assertTrue(IPUtils::networkContains('192.168.1.0/24', '192.168.1.100'));
        $this->assertFalse(IPUtils::networkContains('192.168.1.0/24', '192.168.2.1'));
    }

    public function testNetworkContainsIPv6(): void
    {
        $this->assertTrue(IPUtils::networkContains('2001:db8::/32', '2001:db8::1'));
        $this->assertFalse(IPUtils::networkContains('2001:db8::/32', '2001:db9::1'));
    }

    public function testNetworkContainsMixedVersionReturnsFalse(): void
    {
        $this->assertFalse(IPUtils::networkContains('192.168.1.0/24', '::1'));
    }

    // ─── nextAvailable ───────────────────────────────────────────────────────

    public function testNextAvailableIPv4(): void
    {
        $next = IPUtils::nextAvailable('192.168.1.1', '192.168.1.0/24');
        $this->assertEquals('192.168.1.2', $next);
    }

    public function testNextAvailableIPv4AtEnd(): void
    {
        $next = IPUtils::nextAvailable('192.168.1.254', '192.168.1.0/24');
        $this->assertNull($next);
    }

    public function testNextAvailableIPv6(): void
    {
        $next = IPUtils::nextAvailable('2001:db8::1', '2001:db8::/126');
        $this->assertEquals('2001:db8::2', $next);
    }
}
