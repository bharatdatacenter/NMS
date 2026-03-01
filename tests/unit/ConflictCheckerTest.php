<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Ipam\ConflictChecker;
use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Models\Ipam\IPAllocator;
use MongoDB\BSON\ObjectId;

class ConflictCheckerTest extends TestCase
{
    private ConflictChecker $checker;
    private PoolManager $poolManager;
    private IPAllocator $allocator;
    private string $blockId;
    private string $poolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker     = new ConflictChecker();
        $this->poolManager = new PoolManager();
        $this->allocator   = new IPAllocator();

        $this->blockId = $this->poolManager->createBlock([
            'network'    => '172.31.0.0/16',
            'ip_version' => 'ipv4',
        ]);
        $this->poolId = $this->poolManager->createPool([
            'name'       => 'conflict-test-pool',
            'network'    => '172.31.1.0/24',
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

    public function testNoConflictWhenIpFree(): void
    {
        $conflict = $this->checker->checkConflict('172.31.1.50', $this->poolId);
        $this->assertFalse($conflict);
    }

    public function testConflictDetectedWhenIpActive(): void
    {
        $this->allocator->assignSpecific(
            '172.31.1.50',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-conflict', 'name' => 'test'],
            'layer2'
        );

        $conflict = $this->checker->checkConflict('172.31.1.50', $this->poolId);
        $this->assertTrue($conflict);
    }

    public function testNoConflictAfterRelease(): void
    {
        $this->allocator->assignSpecific(
            '172.31.1.60',
            $this->poolId,
            ['type' => 'server', 'id' => 'srv-release', 'name' => 'test'],
            'layer2'
        );
        $this->allocator->release('172.31.1.60');

        // Released IPs are NOT considered conflicts — they can be re-allocated
        $conflict = $this->checker->checkConflict('172.31.1.60', $this->poolId);
        $this->assertFalse($conflict);
    }

    public function testCheckBGPConflictReturnsEmptyOnException(): void
    {
        // Create a mock adapter that throws on getBGPPrefixesForRange
        $mockAdapter = $this->createMock(\NMS\Core\Models\Devices\DeviceInterface::class);
        $mockAdapter->method('getBGPPrefixesForRange')
                    ->willThrowException(new \RuntimeException('Device unreachable'));

        $conflicts = $this->checker->checkBGPConflict('192.168.1.0/24', $mockAdapter);
        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function testCheckBGPConflictCallsAdapterMethod(): void
    {
        $mockAdapter = $this->createMock(\NMS\Core\Models\Devices\DeviceInterface::class);
        $mockAdapter->method('getBGPPrefixesForRange')
                    ->with('85.209.161.100/32')
                    ->willReturn(['85.209.161.0/24']);

        $conflicts = $this->checker->checkBGPConflict('85.209.161.100/32', $mockAdapter);
        $this->assertCount(1, $conflicts);
        $this->assertEquals('85.209.161.0/24', $conflicts[0]);
    }

    public function testCheckPoolOverlapDetectsConflict(): void
    {
        // The block already has a pool at 172.31.1.0/24
        // A new pool at 172.31.1.128/25 overlaps
        $overlaps = $this->checker->checkPoolOverlap('172.31.1.128/25', $this->blockId);
        $this->assertTrue($overlaps);
    }

    public function testCheckPoolOverlapNoConflictForDistinctRange(): void
    {
        // 172.31.2.0/24 does NOT overlap with 172.31.1.0/24
        $overlaps = $this->checker->checkPoolOverlap('172.31.2.0/24', $this->blockId);
        $this->assertFalse($overlaps);
    }
}
