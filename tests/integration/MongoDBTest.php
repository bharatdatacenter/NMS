<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;

/**
 * @group integration
 * Requires a running MongoDB instance.
 */
class MongoDBTest extends TestCase
{
    private MongoDB $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = MongoDB::getInstance([
            'uri'      => getenv('MONGODB_URI') ?: 'mongodb://localhost:27017',
            'database' => 'nms_test',
            'options'  => ['connectTimeoutMS' => 5000, 'serverSelectionTimeoutMS' => 5000, 'socketTimeoutMS' => 10000],
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test collection
        try {
            $this->db->getDatabase()->dropCollection('_test_collection');
        } catch (\Exception) {}
        parent::tearDown();
    }

    public function testConnection(): void
    {
        $this->assertTrue($this->db->ping(), 'MongoDB ping should succeed');
    }

    public function testBasicCRUD(): void
    {
        $collection = $this->db->selectCollection('_test_collection');

        // Insert
        $result = $collection->insertOne([
            'name'  => 'test-device',
            'value' => 42,
        ]);
        $id = $result->getInsertedId();
        $this->assertInstanceOf(ObjectId::class, $id);

        // Find
        $found = $collection->findOne(['_id' => $id]);
        $this->assertNotNull($found);
        $this->assertEquals('test-device', $found['name']);
        $this->assertEquals(42, $found['value']);

        // Update
        $updateResult = $collection->updateOne(
            ['_id' => $id],
            ['$set' => ['value' => 99]]
        );
        $this->assertEquals(1, $updateResult->getModifiedCount());

        $updated = $collection->findOne(['_id' => $id]);
        $this->assertEquals(99, $updated['value']);

        // Delete
        $deleteResult = $collection->deleteOne(['_id' => $id]);
        $this->assertEquals(1, $deleteResult->getDeletedCount());

        $missing = $collection->findOne(['_id' => $id]);
        $this->assertNull($missing);
    }

    public function testAtomicFindOneAndUpdate(): void
    {
        $collection = $this->db->selectCollection('_test_collection');
        $collection->insertOne(['status' => 'available', 'ip' => '10.0.0.1']);

        // Atomic claim
        $result = $collection->findOneAndUpdate(
            ['status' => 'available'],
            ['$set'   => ['status' => 'assigned']],
            ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        $this->assertNotNull($result);
        $this->assertEquals('assigned', $result['status']);
        $this->assertEquals('10.0.0.1', $result['ip']);
    }

    public function testSingletonPattern(): void
    {
        $instance1 = MongoDB::getInstance();
        $instance2 = MongoDB::getInstance();
        $this->assertSame($instance1, $instance2);
    }
}
