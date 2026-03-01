<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Ipam\SubnetCalculator;
use MongoDB\BSON\ObjectId;

/**
 * Integration test: full IPAM lifecycle.
 *
 * block → pool → assign L2 → assign L3 → release → verify counters return to initial.
 */
class IPAMLifecycleTest extends TestCase
{
    private PoolManager $poolManager;
    private IPAllocator $allocator;
    private string $blockId;
    private string $poolId;
    private string $ipv6BlockId;
    private string $ipv6PoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->poolManager = new PoolManager();
        $this->allocator   = new IPAllocator();

        // IPv4 lifecycle
        $this->blockId = $this->poolManager->createBlock([
            'network'     => '203.0.113.0/24',
            'ip_version'  => 'ipv4',
            'source'      => 'TEST',
            'description' => 'IPv4 lifecycle test block',
        ]);
        $this->poolId = $this->poolManager->createPool([
            'name'        => 'lifecycle-ipv4-pool',
            'network'     => '203.0.113.0/28',
            'block_id'    => $this->blockId,
            'ip_version'  => 'ipv4',
            'pool_type'   => 'public',
        ]);

        // IPv6 lifecycle
        $this->ipv6BlockId = $this->poolManager->createBlock([
            'network'    => '2001:db8::/32',
            'ip_version' => 'ipv6',
        ]);
        $this->ipv6PoolId = $this->poolManager->createPool([
            'name'       => 'lifecycle-ipv6-pool',
            'network'    => '2001:db8:1::/48',
            'block_id'   => $this->ipv6BlockId,
            'ip_version' => 'ipv6',
        ]);
    }

    protected function tearDown(): void
    {
        $db = \NMS\Core\Database\MongoDB::getInstance();
        foreach ([$this->poolId, $this->ipv6PoolId] as $pid) {
            $db->selectCollection('ip_assignments')->deleteMany(['pool_id' => new ObjectId($pid)]);
        }
        $db->selectCollection('ip_assignment_history')->deleteMany([]);
        $db->selectCollection('ip_pools')->deleteMany([
            '_id' => ['$in' => [new ObjectId($this->poolId), new ObjectId($this->ipv6PoolId)]],
        ]);
        $db->selectCollection('ip_blocks')->deleteMany([
            '_id' => ['$in' => [new ObjectId($this->blockId), new ObjectId($this->ipv6BlockId)]],
        ]);
        parent::tearDown();
    }

    // ─── Full IPv4 lifecycle ──────────────────────────────────────────────────

    public function testCreateBlockAndPool(): void
    {
        $block = $this->poolManager->findBlockById($this->blockId);
        $this->assertNotNull($block);
        $this->assertEquals('203.0.113.0/24', $block['network']);
        $this->assertEquals('ipv4', $block['ip_version']);

        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertNotNull($pool);
        $this->assertEquals('203.0.113.0/28', $pool['network']);
        $this->assertEquals(0, $pool['used_addresses']);
        $this->assertEquals(0.0, $pool['utilization_percent']);
    }

    public function testL2AssignmentAndRelease(): void
    {
        $poolBefore = $this->poolManager->findPoolById($this->poolId);
        $initialUsed = (int) ($poolBefore['used_addresses'] ?? 0);

        // Assign L2
        $assignment = $this->allocator->assignSpecific(
            '203.0.113.2',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-l2', 'name' => 'web-01'],
            'layer2',
            'AA:BB:CC:DD:EE:01'
        );

        $this->assertEquals('203.0.113.2', $assignment['ip_address']);
        $this->assertEquals('active', $assignment['status']);
        $this->assertFalse($assignment['routing']['static_route_added']);

        // Counter incremented
        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals($initialUsed + 1, $pool['used_addresses']);

        // Release
        $released = $this->allocator->release('203.0.113.2');
        $this->assertTrue($released);

        // Counter back to initial
        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals($initialUsed, $pool['used_addresses']);
    }

    public function testL3AssignmentSetsRoutingFields(): void
    {
        $assignment = $this->allocator->assignSpecific(
            '203.0.113.3',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-l3', 'name' => 'app-01'],
            'layer3',
            'AA:BB:CC:DD:EE:02'
        );

        $this->assertEquals('layer3', $assignment['assignment_type']);
        $this->assertFalse($assignment['routing']['static_route_added']);

        // Simulate route + ARP being pushed
        $this->allocator->markL3('203.0.113.3', [
            'gateway_ip'           => '203.0.113.1',
            'static_route_added'   => true,
            'neighbor_entry_added' => true,
        ]);

        $updated = $this->allocator->findByIp('203.0.113.3');
        $this->assertTrue($updated['routing']['static_route_added']);
        $this->assertTrue($updated['routing']['neighbor_entry_added']);
        $this->assertEquals('203.0.113.1', $updated['routing']['gateway_ip']);
    }

    public function testAssignmentHistoryWrittenOnEachStateChange(): void
    {
        $this->allocator->assignSpecific(
            '203.0.113.4',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-hist', 'name' => 'hist-01']
        );
        $this->allocator->release('203.0.113.4');

        $history = $this->allocator->getHistory('203.0.113.4');
        $this->assertGreaterThanOrEqual(2, $history['total']);

        $actions = array_column($history['data'], 'action');
        $this->assertContains('assigned', $actions);
        $this->assertContains('released', $actions);
    }

    // ─── IPv6 lifecycle ───────────────────────────────────────────────────────

    public function testIPv6PoolHasPrefixTracking(): void
    {
        $pool = $this->poolManager->findPoolById($this->ipv6PoolId);
        $this->assertNotNull($pool);
        $this->assertEquals('ipv6', $pool['ip_version']);
        $this->assertArrayHasKey('allocated_prefixes', $pool);
        $this->assertArrayHasKey('total_prefixes', $pool);
        $this->assertEquals(0, $pool['allocated_prefixes']);
        // /48 has 2^16 = 65536 /64 blocks
        $this->assertEquals(65536, $pool['total_prefixes']);
    }

    public function testIPv6AllocationIncrementsAllocatedPrefixes(): void
    {
        $this->allocator->allocateNext(
            $this->ipv6PoolId,
            ['type' => 'server', 'id' => 'srv-ipv6', 'name' => 'ipv6-server'],
            'AA:BB:CC:DD:EE:06'
        );

        $pool = $this->poolManager->findPoolById($this->ipv6PoolId);
        $this->assertEquals(1, $pool['allocated_prefixes']);
        $this->assertEquals(1, $pool['used_addresses']);
    }

    public function testIPv6ReleaseDecrementsAllocatedPrefixes(): void
    {
        $assignment = $this->allocator->allocateNext(
            $this->ipv6PoolId,
            ['type' => 'server', 'id' => 'srv-ipv6-2', 'name' => 'ipv6-srv-2'],
            'AA:BB:CC:DD:EE:07'
        );

        $this->allocator->release($assignment['ip_address']);

        $pool = $this->poolManager->findPoolById($this->ipv6PoolId);
        $this->assertEquals(0, $pool['allocated_prefixes']);
        $this->assertEquals(0, $pool['used_addresses']);
    }

    // ─── SubnetCalculator edge cases ─────────────────────────────────────────

    public function testSubnetCalculatorMatchesPoolMetadata(): void
    {
        $calc = new SubnetCalculator();
        $pool = $this->poolManager->findPoolById($this->poolId);

        $range = $calc->getUsableRange($pool['network']);

        $this->assertEquals($pool['first_usable_ip'], $range['first']);
        $this->assertEquals($pool['last_usable_ip'], $range['last']);
        $this->assertEquals((int) $pool['total_addresses'], $range['total']);
    }

    public function testUtilizationGoesToZeroAfterFullRelease(): void
    {
        // Assign all 14 usable IPs in a /28
        $assigned = [];
        for ($i = 0; $i < 5; $i++) {
            $a = $this->allocator->allocateNext(
                $this->poolId,
                ['type' => 'server', 'id' => "s{$i}", 'name' => "s{$i}"]
            );
            $assigned[] = $a['ip_address'];
        }

        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertGreaterThan(0.0, $pool['utilization_percent']);

        // Release all
        foreach ($assigned as $ip) {
            $this->allocator->release($ip);
        }

        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals(0, $pool['used_addresses']);
        $this->assertEquals(0.0, $pool['utilization_percent']);
    }
}
