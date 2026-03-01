<?php

declare(strict_types=1);

use NMS\Core\Models\Firewall\PolicyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * PolicyBuilderIPv4Test
 *
 * Verifies that IPv4 inbound/outbound policies are built with the correct
 * NAT fields (nat_enabled=true, nat_type="destination", vip_id set).
 */
class PolicyBuilderIPv4Test extends TestCase
{
    private PolicyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PolicyBuilder();
    }

    public function testIPv4InboundPolicyHasNAT(): void
    {
        $params = [
            'device_id'   => 'aaa000000000000000000001',
            'name'        => 'Allow-Web-01-HTTPS',
            'vip_id'      => 'bbb000000000000000000001',
            'mapped_ip'   => '10.0.1.50',
            'mapped_port' => 443,
            'services'    => ['ccc000000000000000000001'],
        ];

        $policy = $this->builder->buildIPv4InboundPolicy($params);

        $this->assertSame('ipv4', $policy['ip_version']);
        $this->assertSame('inbound', $policy['direction']);
        $this->assertTrue($policy['nat_enabled'], 'IPv4 inbound must have nat_enabled=true');
        $this->assertSame('destination', $policy['nat_type']);
        $this->assertSame('bbb000000000000000000001', $policy['vip_id']);
        $this->assertSame('10.0.1.50', $policy['mapped_ip']);
        $this->assertSame(443, $policy['mapped_port']);
        $this->assertSame('wan', $policy['source_zone']);
        $this->assertSame('internal', $policy['destination_zone']);
    }

    public function testIPv4InboundDefaultZones(): void
    {
        $policy = $this->builder->buildIPv4InboundPolicy([
            'device_id' => 'aaa000000000000000000001',
            'name'      => 'Test',
            'vip_id'    => 'bbb000000000000000000001',
            'mapped_ip' => '10.0.0.1',
        ]);

        $this->assertSame('wan', $policy['source_zone']);
        $this->assertSame('internal', $policy['destination_zone']);
        $this->assertSame('allow', $policy['action']);
    }

    public function testIPv4OutboundPolicyNoVip(): void
    {
        $params = [
            'device_id' => 'aaa000000000000000000001',
            'name'      => 'Allow-Outbound',
        ];

        $policy = $this->builder->buildIPv4OutboundPolicy($params);

        $this->assertSame('ipv4', $policy['ip_version']);
        $this->assertSame('outbound', $policy['direction']);
        $this->assertNull($policy['vip_id'], 'Outbound policies have no VIP');
        $this->assertNull($policy['mapped_port']);
        $this->assertSame('internal', $policy['source_zone']);
        $this->assertSame('wan', $policy['destination_zone']);
    }

    public function testIPv4OutboundNATOptional(): void
    {
        $policy = $this->builder->buildIPv4OutboundPolicy([
            'device_id'   => 'aaa000000000000000000001',
            'name'        => 'SNAT-Outbound',
            'nat_enabled' => true,
            'nat_type'    => 'source',
            'mapped_ip'   => '203.0.113.1',
        ]);

        $this->assertTrue($policy['nat_enabled']);
        $this->assertSame('source', $policy['nat_type']);
        $this->assertSame('203.0.113.1', $policy['mapped_ip']);
    }
}
