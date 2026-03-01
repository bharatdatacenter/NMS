<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;

/**
 * Unit tests for CableManager.
 *
 * Tests endpoint validation:
 *  - Rejects cable if a device endpoint does not exist
 *  - Rejects cable if a port is already connected to another cable
 */
class CableManagerTest extends TestCase
{
    private CableManager $cm;
    private DeviceManager $dm;
    private string $deviceAId;
    private string $deviceBId;
    private string $testCableId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cm = new CableManager();
        $this->dm = new DeviceManager();

        // Create two test devices
        $this->deviceAId = $this->dm->create([
            'name'   => 'TestDeviceA-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
            'ports'  => [
                ['name' => 'eth0', 'type' => 'ethernet'],
                ['name' => 'eth1', 'type' => 'ethernet'],
            ],
        ], 'test');

        $this->deviceBId = $this->dm->create([
            'name'   => 'TestDeviceB-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
            'ports'  => [
                ['name' => 'eth0', 'type' => 'ethernet'],
            ],
        ], 'test');

        $this->testCableId = 'TEST-' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $db = MongoDB::getInstance();
        $db->selectCollection('devices')->deleteMany(['name' => ['$regex' => '^TestDevice[AB]-']]);
        $db->selectCollection('cables')->deleteMany(['cable_id' => ['$regex' => '^TEST-']]);
        $db->selectCollection('connectivity_paths')->deleteMany([]);
        parent::tearDown();
    }

    // ─── Validation: device existence ─────────────────────────────────────────

    public function testRejectsNonExistentEndpointA(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Device not found.*endpoint_a/');

        $this->cm->validateEndpoints(
            ['device_id' => (string)new ObjectId(), 'port_name' => 'eth0'],
            ['device_id' => $this->deviceBId,       'port_name' => 'eth0']
        );
    }

    public function testRejectsNonExistentEndpointB(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Device not found.*endpoint_b/');

        $this->cm->validateEndpoints(
            ['device_id' => $this->deviceAId,       'port_name' => 'eth0'],
            ['device_id' => (string)new ObjectId(),  'port_name' => 'eth0']
        );
    }

    public function testMissingDeviceIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing device_id/');

        $this->cm->validateEndpoints(
            ['port_name' => 'eth0'],
            ['device_id' => $this->deviceBId, 'port_name' => 'eth0']
        );
    }

    // ─── Validation: port already connected ───────────────────────────────────

    public function testRejectsAlreadyConnectedPort(): void
    {
        // Create first cable using port eth0 on device A
        $this->cm->create([
            'cable_id'   => $this->testCableId,
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');

        // Second cable attempting to use the same port should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already connected/');

        $this->cm->create([
            'cable_id'   => 'TEST-' . uniqid(),
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'], // same port
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');
    }

    // ─── Successful creation ──────────────────────────────────────────────────

    public function testCreatesCableSuccessfully(): void
    {
        $id = $this->cm->create([
            'cable_id'   => $this->testCableId,
            'cable_type' => 'cat6a',
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');

        $this->assertNotEmpty($id);

        $cable = $this->cm->findById($id);
        $this->assertEquals($this->testCableId, $cable['cable_id']);
        $this->assertEquals('active', $cable['status']);
    }

    public function testListForDeviceReturnsBothEndpoints(): void
    {
        $this->cm->create([
            'cable_id'   => $this->testCableId,
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');

        $cablesA = $this->cm->listForDevice($this->deviceAId);
        $this->assertCount(1, $cablesA);

        $cablesB = $this->cm->listForDevice($this->deviceBId);
        $this->assertCount(1, $cablesB);
    }

    public function testDifferentPortsOnSameDeviceAllowed(): void
    {
        // eth0 to device B
        $id1 = $this->cm->create([
            'cable_id'   => $this->testCableId,
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');

        // eth1 on device A is a different port — should succeed
        $deviceCId = $this->dm->create([
            'name'   => 'TestDeviceC-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
        ], 'test');

        $id2 = $this->cm->create([
            'cable_id'   => 'TEST-' . uniqid(),
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth1'],
            'endpoint_b' => ['device_id' => $deviceCId, 'port_name' => 'eth0'],
        ], 'test');

        $this->assertNotEmpty($id1);
        $this->assertNotEmpty($id2);

        // Cleanup
        $this->dm->deleteById($deviceCId);
    }
}
