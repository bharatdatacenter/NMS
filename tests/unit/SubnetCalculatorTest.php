<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Ipam\SubnetCalculator;

class SubnetCalculatorTest extends TestCase
{
    private SubnetCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SubnetCalculator();
    }

    // ─── IPv4 usable range ───────────────────────────────────────────────────

    public function testIPv4UsableRange(): void
    {
        $range = $this->calc->getUsableRange('192.168.1.0/24');
        $this->assertEquals('192.168.1.1', $range['first']);
        $this->assertEquals('192.168.1.254', $range['last']);
        $this->assertEquals(254, $range['total']);
    }

    public function testIPv4SlashThirtyTwo(): void
    {
        $range = $this->calc->getUsableRange('10.0.0.1/32');
        $this->assertEquals(1, $range['total']);
        $this->assertEquals('10.0.0.1', $range['first']);
        $this->assertEquals('10.0.0.1', $range['last']);
    }

    // ─── IPv6 all-usable (no broadcast) ──────────────────────────────────────

    public function testIPv6AllAddressesUsable(): void
    {
        $range = $this->calc->getUsableRange('2001:db8::/126');
        // /126 = 4 addresses, ALL usable in IPv6 (no broadcast)
        $this->assertEquals(4, $range['total']);
    }

    public function testIPv6SlashOneTwentyEight(): void
    {
        $range = $this->calc->getUsableRange('2001:db8::50/128');
        $this->assertEquals(1, $range['total']);
        $this->assertEquals('2001:db8::50', $range['first']);
    }

    // ─── IPv6 /64 counting ───────────────────────────────────────────────────

    public function testCountIPv6PrefixesForSlash32(): void
    {
        // A /32 contains 2^32 /64 blocks — too large for int, returns string
        $count = $this->calc->countIPv6Prefixes('2001:db8::/32', 64);
        $this->assertEquals('2^32', $count);
    }

    public function testCountIPv6PrefixesForSlash48(): void
    {
        // A /48 contains 2^16 = 65536 /64 blocks
        $count = $this->calc->countIPv6Prefixes('2001:db8::/48', 64);
        $this->assertEquals(65536, $count);
    }

    public function testCountIPv6PrefixesForSlash56(): void
    {
        // A /56 contains 2^8 = 256 /64 blocks
        $count = $this->calc->countIPv6Prefixes('2001:db8::/56', 64);
        $this->assertEquals(256, $count);
    }

    public function testCountIPv6PrefixesThrowsForIPv4(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->countIPv6Prefixes('192.168.0.0/24', 64);
    }

    // ─── networkContains ─────────────────────────────────────────────────────

    public function testNetworkContainsIPv4(): void
    {
        $this->assertTrue($this->calc->networkContains('10.0.0.0/8', '10.0.1.1'));
        $this->assertFalse($this->calc->networkContains('10.0.0.0/8', '11.0.0.1'));
    }

    public function testNetworkContainsIPv6(): void
    {
        $this->assertTrue($this->calc->networkContains('2001:db8::/32', '2001:db8::1'));
        $this->assertFalse($this->calc->networkContains('2001:db8::/32', '2001:db9::1'));
    }

    // ─── cidrsOverlap ────────────────────────────────────────────────────────

    public function testOverlappingCIDRs(): void
    {
        $this->assertTrue($this->calc->cidrsOverlap('192.168.0.0/16', '192.168.1.0/24'));
    }

    public function testNonOverlappingCIDRs(): void
    {
        $this->assertFalse($this->calc->cidrsOverlap('10.0.0.0/8', '192.168.0.0/16'));
    }

    public function testMixedVersionNeverOverlaps(): void
    {
        $this->assertFalse($this->calc->cidrsOverlap('10.0.0.0/8', '2001:db8::/32'));
    }

    // ─── utilizationPercent ──────────────────────────────────────────────────

    public function testUtilizationPercent(): void
    {
        $this->assertEquals(50.0, $this->calc->utilizationPercent(127, 254));
        $this->assertEquals(0.0, $this->calc->utilizationPercent(0, 0));
        $this->assertEquals(100.0, $this->calc->utilizationPercent(254, 254));
    }

    // ─── detectVersion ───────────────────────────────────────────────────────

    public function testDetectVersion(): void
    {
        $this->assertEquals('ipv4', $this->calc->detectVersion('192.168.1.0/24'));
        $this->assertEquals('ipv6', $this->calc->detectVersion('2001:db8::/32'));
    }
}
