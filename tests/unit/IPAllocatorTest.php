<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Ipam\PoolManager;

/**
 * Unit tests for IPAllocator — L2 vs L3 field differences.
 * Uses nms_test MongoDB database.
 */
class IPAllocatorTest extends TestCase
{
    private IPAllocator $allocator;
    private PoolManager $poolManager;
    private string $blockId;
    private string $poolId;
    private string $ipv6PoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator   = new IPAllocator();
        $this->poolManager = new PoolManager();

        // Create a test block + pool
        $this->blockId = $this->poolManager->createBlock([
            'network'     => '10.99.0.0/16',
            'ip_version'  => 'ipv4',
            'description' => 'Test block',
        ]);
        $this->poolId = $this->poolManager->createPool([
            'name'        => 'test-pool-allocator',
            'network'     => '10.99.1.0/28',
            'block_id'    => $this->blockId,
            'ip_version'  => 'ipv4',
        ]);

        // Create an IPv6 pool for prefix tracking tests
        $this->ipv6PoolId = $this->poolManager->createPool([
            'name'       => 'test-ipv6-pool',
            'network'    => '2001:db8:99::/48',
            'ip_version' => 'ipv6',
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $db = \NMS\Core\Database\MongoDB::getInstance();
        $db->selectCollection('ip_assignments')->deleteMany(['pool_id' => new \MongoDB\BSON\ObjectId($this->poolId)]);
        $db->selectCollection('ip_assignments')->deleteMany(['pool_id' => new \MongoDB\BSON\ObjectId($this->ipv6PoolId)]);
        $db->selectCollection('ip_assignment_history')->deleteMany([]);
        $db->selectCollection('ip_pools')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($this->poolId)]);
        $db->selectCollection('ip_pools')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($this->ipv6PoolId)]);
        $db->selectCollection('ip_blocks')->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($this->blockId)]);
        parent::tearDown();
    }

    // ─── L2 vs L3 field differences ──────────────────────────────────────────

    public function testL2AssignmentHasRouteAddedFalse(): void
    {
        $assignment = $this->allocator->allocateNext(
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-001', 'name' => 'web-01'],
            'AA:BB:CC:DD:EE:01',
            'layer2'
        );

        $this->assertEquals('active', $assignment['status']);
        $this->assertEquals('layer2', $assignment['assignment_type']);
        $this->assertFalse($assignment['routing']['static_route_added']);
        $this->assertFalse($assignment['routing']['neighbor_entry_added']);
        $this->assertNull($assignment['routing']['route_id']);
        $this->assertNull($assignment['routing']['neighbor_id']);
    }

    public function testMarkL3SetsRoutingFields(): void
    {
        $assignment = $this->allocator->assignSpecific(
            '10.99.1.2',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-002', 'name' => 'web-02'],
            'layer3',
            'AA:BB:CC:DD:EE:02'
        );

        $this->assertEquals('10.99.1.2', $assignment['ip_address']);
        $this->assertFalse($assignment['routing']['static_route_added']);

        // Now mark it as L3
        $this->allocator->markL3('10.99.1.2', [
            'gateway_ip'           => '10.99.1.1',
            'static_route_added'   => true,
            'neighbor_entry_added' => true,
        ]);

        $updated = $this->allocator->findByIp('10.99.1.2');
        $this->assertTrue($updated['routing']['static_route_added']);
        $this->assertTrue($updated['routing']['neighbor_entry_added']);
        $this->assertEquals('10.99.1.1', $updated['routing']['gateway_ip']);
    }

    // ─── Release ─────────────────────────────────────────────────────────────

    public function testReleaseDecrementsPoolCounter(): void
    {
        $this->allocator->assignSpecific(
            '10.99.1.5',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-005', 'name' => 'srv'],
            'layer2'
        );

        $pool = $this->poolManager->findPoolById($this->poolId);
        $usedBefore = (int) $pool['used_addresses'];

        $this->allocator->release('10.99.1.5');

        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals($usedBefore - 1, (int) $pool['used_addresses']);
    }

    public function testReleaseNonActiveReturnsFalse(): void
    {
        $result = $this->allocator->release('10.99.1.250');
        $this->assertFalse($result);
    }

    // ─── History is written ───────────────────────────────────────────────────

    public function testHistoryWrittenOnAssignment(): void
    {
        $this->allocator->assignSpecific(
            '10.99.1.9',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-009', 'name' => 'srv'],
            'layer2'
        );

        $history = $this->allocator->getHistory('10.99.1.9');
        $this->assertGreaterThanOrEqual(1, $history['total']);
        $this->assertEquals('assigned', $history['data'][0]['action']);
    }

    // ─── IPv6 allocated_prefixes ──────────────────────────────────────────────

    public function testIPv6AllocationIncrementsAllocatedPrefixes(): void
    {
        $poolBefore = $this->poolManager->findPoolById($this->ipv6PoolId);
        $prefixesBefore = (int) ($poolBefore['allocated_prefixes'] ?? 0);

        $this->allocator->allocateNext(
            $this->ipv6PoolId,
            ['type' => 'server', 'id' => 'srv-ipv6', 'name' => 'ipv6-test'],
            'AA:BB:CC:DD:EE:06',
            'layer2'
        );

        $poolAfter = $this->poolManager->findPoolById($this->ipv6PoolId);
        $this->assertEquals($prefixesBefore + 1, (int) ($poolAfter['allocated_prefixes'] ?? 0));
    }
}
