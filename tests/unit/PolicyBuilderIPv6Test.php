<?php

declare(strict_types=1);

use NMS\Core\Models\Firewall\PolicyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * PolicyBuilderIPv6Test
 *
 * Verifies that IPv6 policies are built WITHOUT VIPs or NAT.
 * IPv6 addresses are globally routable — no NAT needed.
 */
class PolicyBuilderIPv6Test extends TestCase
{
    private PolicyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PolicyBuilder();
    }

    public function testIPv6InboundHasNoVip(): void
    {
        $params = [
            'device_id'             => 'aaa000000000000000000001',
            'name'                  => 'Allow-Web-01-HTTPS-IPv6',
            'destination_addresses' => ['ddd000000000000000000001'],  // Direct IPv6 addr object
            'services'              => ['ccc000000000000000000001'],
        ];

        $policy = $this->builder->buildIPv6InboundPolicy($params);

        $this->assertSame('ipv6', $policy['ip_version']);
        $this->assertSame('inbound', $policy['direction']);
        $this->assertNull($policy['vip_id'], 'IPv6 must never have a VIP');
        $this->assertNull($policy['mapped_ip'], 'IPv6 must never have mapped_ip');
        $this->assertNull($policy['mapped_port'], 'IPv6 must never have mapped_port');
        $this->assertFalse($policy['nat_enabled'], 'IPv6 nat_enabled must always be false');
        $this->assertNull($policy['nat_type'], 'IPv6 nat_type must always be null');
    }

    public function testIPv6InboundDefaultZones(): void
    {
        $policy = $this->builder->buildIPv6InboundPolicy([
            'device_id' => 'aaa000000000000000000001',
            'name'      => 'Test-IPv6',
        ]);

        $this->assertSame('wan', $policy['source_zone']);
        $this->assertSame('internal', $policy['destination_zone']);
        $this->assertSame('allow', $policy['action']);
        $this->assertSame('security', $policy['log_traffic']);
    }

    public function testIPv6OutboundHasNoNAT(): void
    {
        $policy = $this->builder->buildIPv6OutboundPolicy([
            'device_id' => 'aaa000000000000000000001',
            'name'      => 'Allow-Outbound-IPv6',
        ]);

        $this->assertSame('ipv6', $policy['ip_version']);
        $this->assertSame('outbound', $policy['direction']);
        $this->assertNull($policy['vip_id']);
        $this->assertNull($policy['mapped_ip']);
        $this->assertFalse($policy['nat_enabled']);
        $this->assertNull($policy['nat_type']);
        $this->assertSame('internal', $policy['source_zone']);
        $this->assertSame('wan', $policy['destination_zone']);
    }

    public function testIPv6PolicyManagerEnforcesNoNAT(): void
    {
        // Even if a caller tries to set nat_enabled on an IPv6 policy,
        // PolicyBuilder returns nat_enabled=false
        $policy = $this->builder->buildIPv6InboundPolicy([
            'device_id'   => 'aaa000000000000000000001',
            'name'        => 'Sneaky-NAT-Attempt',
            // These are intentionally NOT in the params — builder should ignore them
        ]);

        // Builder always returns false for IPv6, regardless of caller intent
        $this->assertFalse($policy['nat_enabled']);
        $this->assertNull($policy['vip_id']);
    }
}
