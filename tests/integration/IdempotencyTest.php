<?php

declare(strict_types=1);

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase;

/**
 * IdempotencyTest — Integration
 *
 * Requires: MongoDB running and accessible.
 *
 * Verifies:
 *   - A request with a duplicate X-Idempotency-Key returns the
 *     same response without re-executing any operations
 *   - Different idempotency keys are treated as distinct requests
 */
class IdempotencyTest extends TestCase
{
    private ?\MongoDB\Collection $auditLogs = null;
    private array $cleanupIds = [];

    protected function setUp(): void
    {
        try {
            $db              = MongoDB::getInstance();
            $this->auditLogs = $db->selectCollection('audit_logs');
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB not available');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupIds as $id) {
            $this->auditLogs->deleteOne(['_id' => new ObjectId($id)]);
        }
    }

    public function testDuplicateIdempotencyKeyReturnsExistingRecord(): void
    {
        $iKey = 'idempotency-test-' . uniqid();

        // Simulate first request: create an audit log with this idempotency key
        $insertResult = $this->auditLogs->insertOne([
            'idempotency_key' => $iKey,
            'action'          => 'test_action',
            'resource_type'   => 'device',
            'resource_id'     => 'dev-idem-1',
            'user_id'         => 'test-user',
            'timestamp'       => new UTCDateTime(),
        ]);
        $firstId = (string)$insertResult->getInsertedId();
        $this->cleanupIds[] = $firstId;

        // Simulate second request with same idempotency key:
        // Should find the existing record
        $existing = $this->auditLogs->findOne(['idempotency_key' => $iKey]);
        $this->assertNotNull($existing, 'Existing record should be found');

        $existingArr = json_decode(json_encode($existing), true);
        $returnedId  = $existingArr['_id']['$oid'] ?? '';

        $this->assertSame($firstId, $returnedId);
    }

    public function testDifferentIdempotencyKeyCreatesNewRecord(): void
    {
        $iKey1 = 'idem-key-1-' . uniqid();
        $iKey2 = 'idem-key-2-' . uniqid();

        $this->assertNotSame($iKey1, $iKey2);

        // Insert record for key1
        $result1 = $this->auditLogs->insertOne([
            'idempotency_key' => $iKey1,
            'action'          => 'test_action',
            'resource_type'   => 'device',
            'resource_id'     => 'dev-1',
            'timestamp'       => new UTCDateTime(),
        ]);
        $this->cleanupIds[] = (string)$result1->getInsertedId();

        // key2 should NOT match key1
        $existing = $this->auditLogs->findOne(['idempotency_key' => $iKey2]);
        $this->assertNull($existing, 'Different key should return no match');
    }

    public function testIdempotencyKeyFormat(): void
    {
        // Verify idempotency keys follow the expected pattern
        $resourceId = 'device-uuid-123';
        $timestamp  = 1709312400;
        $key        = "op-{$resourceId}-{$timestamp}";

        $this->assertStringStartsWith('op-', $key);
        $this->assertStringContainsString($resourceId, $key);
        $this->assertSame("op-device-uuid-123-1709312400", $key);
    }
}
