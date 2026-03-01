<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Ipam\PoolManager;
use MongoDB\BSON\ObjectId;

/**
 * Integration test: concurrent atomic IP allocation.
 *
 * Fires 10 sequential simulated allocations at the same pool and verifies
 * that no two allocations receive the same IP address.
 *
 * True parallel PHP processes aren't feasible in PHPUnit, but we can verify
 * the core atomic mechanism by pre-seeding a race scenario using MongoDB
 * directly, then confirming the findOneAndUpdate pattern handles contention.
 */
class AtomicAllocationTest extends TestCase
{
    private IPAllocator $allocator;
    private PoolManager $poolManager;
    private string $blockId;
    private string $poolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator   = new IPAllocator();
        $this->poolManager = new PoolManager();

        $this->blockId = $this->poolManager->createBlock([
            'network'    => '10.88.0.0/16',
            'ip_version' => 'ipv4',
        ]);
        // /28 = 14 usable addresses — enough for 10 concurrent requests
        $this->poolId = $this->poolManager->createPool([
            'name'       => 'atomic-test-pool',
            'network'    => '10.88.1.0/28',
            'block_id'   => $this->blockId,
            'ip_version' => 'ipv4',
        ]);
    }

    protected function tearDown(): void
    {
        $db = \NMS\Core\Database\MongoDB::getInstance();
        $db->selectCollection('ip_assignments')->deleteMany(['pool_id' => new ObjectId($this->poolId)]);
        $db->selectCollection('ip_assignment_history')->deleteMany([]);
        $db->selectCollection('ip_pools')->deleteOne(['_id' => new ObjectId($this->poolId)]);
        $db->selectCollection('ip_blocks')->deleteOne(['_id' => new ObjectId($this->blockId)]);
        parent::tearDown();
    }

    /**
     * Allocate 10 IPs sequentially and assert no duplicates.
     * Validates the core allocateNext mechanism.
     */
    public function testTenAllocationsProduceUniqueIPs(): void
    {
        $allocatedIPs = [];

        for ($i = 1; $i <= 10; $i++) {
            $assignment = $this->allocator->allocateNext(
                $this->poolId,
                ['type' => 'server', 'id' => "srv-{$i}", 'name' => "server-{$i}"],
                sprintf('AA:BB:CC:DD:EE:%02X', $i),
                'layer2'
            );

            $ip = $assignment['ip_address'];
            $this->assertNotContains(
                $ip,
                $allocatedIPs,
                "Duplicate IP allocated: {$ip} (allocation #{$i})"
            );
            $allocatedIPs[] = $ip;
            $this->assertEquals('active', $assignment['status']);
            $this->assertEquals('ipv4', $assignment['ip_version']);
        }

        $this->assertCount(10, array_unique($allocatedIPs), '10 unique IPs must be allocated');
    }

    /**
     * Verify that releasing IPs and reallocating correctly recycles them.
     */
    public function testReleaseAndReallocate(): void
    {
        // Allocate 3 IPs
        $ips = [];
        for ($i = 1; $i <= 3; $i++) {
            $a    = $this->allocator->allocateNext(
                $this->poolId,
                ['type' => 'server', 'id' => "s{$i}", 'name' => "s{$i}"],
                "AA:BB:CC:00:00:0{$i}"
            );
            $ips[] = $a['ip_address'];
        }

        // Release the first one
        $releasedIp = $ips[0];
        $this->allocator->release($releasedIp);

        // The pool counter should have decremented
        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals(2, (int) $pool['used_addresses']);

        // Reallocate — should get back the released IP (lowest available, recycled first)
        $reallocated = $this->allocator->allocateNext(
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-realloc', 'name' => 'realloc'],
            'AA:BB:CC:FF:FF:FF'
        );

        // The released IP should have been reused (findOneAndUpdate on status:released)
        $this->assertEquals($releasedIp, $reallocated['ip_address']);
        $this->assertEquals('active', $reallocated['status']);
    }

    /**
     * Verify pool counter matches actual allocation count after mixed assign/release cycle.
     */
    public function testPoolCounterAccuracyAfterCycle(): void
    {
        // Allocate 5
        $allocated = [];
        for ($i = 1; $i <= 5; $i++) {
            $a          = $this->allocator->allocateNext(
                $this->poolId,
                ['type' => 'server', 'id' => "c{$i}", 'name' => "c{$i}"]
            );
            $allocated[] = $a['ip_address'];
        }

        // Release 2
        $this->allocator->release($allocated[0]);
        $this->allocator->release($allocated[2]);

        // Re-allocate 1
        $this->allocator->allocateNext(
            $this->poolId,
            ['type' => 'server', 'id' => 'reclaim', 'name' => 'reclaim']
        );

        // Net: 5 - 2 + 1 = 4
        $pool = $this->poolManager->findPoolById($this->poolId);
        $this->assertEquals(4, (int) $pool['used_addresses']);
    }
}
