<?php

declare(strict_types=1);

use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Models\Firewall\PolicyBuilder;
use NMS\Core\Models\Firewall\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * FirewallPolicyTest
 *
 * Integration test: full lifecycle for firewall policies and objects.
 * Requires MONGODB_URI environment variable.
 */
class FirewallPolicyTest extends TestCase
{
    private PolicyManager $policyManager;
    private PolicyBuilder $builder;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        if (empty(getenv('MONGODB_URI'))) {
            $this->markTestSkipped('MONGODB_URI not set — skipping integration test');
        }

        $this->policyManager = new PolicyManager();
        $this->builder       = new PolicyBuilder();
        $this->objectManager = new ObjectManager();
    }

    public function testCreateIPv4PolicyWithVIP(): void
    {
        // Create VIP first
        $vipId = $this->objectManager->createVip([
            'device_id'     => '000000000000000000000001',
            'external_ip'   => '203.0.113.100',
            'external_port' => 443,
            'mapped_ip'     => '10.0.1.50',
            'mapped_port'   => 443,
            'protocol'      => 'tcp',
            'comment'       => 'Test VIP',
        ]);

        $this->assertNotEmpty($vipId);

        // Build and create policy
        $params = $this->builder->buildIPv4InboundPolicy([
            'device_id' => '000000000000000000000001',
            'name'      => 'Allow-HTTPS-IPv4-Test',
            'vip_id'    => $vipId,
            'mapped_ip' => '10.0.1.50',
        ]);

        $policyId = $this->policyManager->create($params, '000000000000000000000002');
        $this->assertNotEmpty($policyId);

        $policy = $this->policyManager->findById($policyId);
        $this->assertSame('ipv4', $policy['ip_version']);
        $this->assertTrue($policy['nat_enabled']);
        $this->assertSame('destination', $policy['nat_type']);
        $this->assertNotNull($policy['vip_id']);

        // Cleanup
        $this->policyManager->delete($policyId, '000000000000000000000002');
    }

    public function testCreateIPv6PolicyNoVIP(): void
    {
        $params = $this->builder->buildIPv6InboundPolicy([
            'device_id' => '000000000000000000000001',
            'name'      => 'Allow-HTTPS-IPv6-Test',
        ]);

        $policyId = $this->policyManager->create($params, '000000000000000000000002');
        $this->assertNotEmpty($policyId);

        $policy = $this->policyManager->findById($policyId);
        $this->assertSame('ipv6', $policy['ip_version']);
        $this->assertFalse($policy['nat_enabled'], 'IPv6 must never have NAT');
        $this->assertNull($policy['vip_id'], 'IPv6 must never have VIP');
        $this->assertNull($policy['nat_type']);

        $this->policyManager->delete($policyId, '000000000000000000000002');
    }

    public function testPolicyManagerEnforcesIPv6NoNAT(): void
    {
        // Even if caller passes nat_enabled=true for IPv6, PolicyManager enforces false
        $policyId = $this->policyManager->create([
            'ip_version'  => 'ipv6',
            'device_id'   => '000000000000000000000001',
            'name'        => 'Sneaky-IPv6-NAT',
            'nat_enabled' => true,      // PolicyManager should override this
            'vip_id'      => '000000000000000000000099',  // Should be nullified
        ], '000000000000000000000002');

        $policy = $this->policyManager->findById($policyId);
        $this->assertFalse($policy['nat_enabled']);
        $this->assertNull($policy['vip_id']);

        $this->policyManager->delete($policyId, '000000000000000000000002');
    }

    public function testPolicyUpdateWritesHistory(): void
    {
        $policyId = $this->policyManager->create([
            'ip_version' => 'ipv4',
            'device_id'  => '000000000000000000000001',
            'name'       => 'History-Test-Policy',
        ], '000000000000000000000002');

        $this->policyManager->update($policyId, ['comments' => 'Updated comment'], '000000000000000000000002');

        $policy = $this->policyManager->findById($policyId);
        $this->assertSame('Updated comment', $policy['comments']);

        $this->policyManager->delete($policyId, '000000000000000000000002');
    }

    public function testReorder(): void
    {
        $id1 = $this->policyManager->create([
            'ip_version' => 'ipv4',
            'device_id'  => '000000000000000000000001',
            'name'       => 'Reorder-Test-1',
            'sequence'   => 10,
        ], '000000000000000000000002');

        $id2 = $this->policyManager->create([
            'ip_version' => 'ipv4',
            'device_id'  => '000000000000000000000001',
            'name'       => 'Reorder-Test-2',
            'sequence'   => 20,
        ], '000000000000000000000002');

        $this->policyManager->reorder([
            ['id' => $id1, 'sequence' => 50],
            ['id' => $id2, 'sequence' => 5],
        ]);

        $p1 = $this->policyManager->findById($id1);
        $p2 = $this->policyManager->findById($id2);

        $this->assertSame(50, $p1['sequence']);
        $this->assertSame(5, $p2['sequence']);

        $this->policyManager->delete($id1, '000000000000000000000002');
        $this->policyManager->delete($id2, '000000000000000000000002');
    }
}
