<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Models\Infrastructure\SiteManager;
use NMS\Core\Models\Infrastructure\RackManager;
use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Database\MongoDB;

/**
 * Integration test: Full physical infrastructure lifecycle.
 *
 * Flow:
 *   1. Create site
 *   2. Create rack in that site
 *   3. Create device in that rack
 *   4. Create patch panel in that rack
 *   5. Create switch in that rack
 *   6. Register cable: device:eth0 → PP:F-01
 *   7. Register cable: PP:R-01 → switch:ge-0/0/1
 *   8. Trace path from device:eth0 → switch:ge-0/0/1
 *   9. Verify patch panel traversal is correct
 */
class InfrastructureTest extends TestCase
{
    private SiteManager      $siteManager;
    private RackManager      $rackManager;
    private DeviceManager    $deviceManager;
    private CableManager     $cableManager;
    private PathMaterializer $pm;

    private string $siteId;
    private string $rackId;
    private string $serverId;
    private string $patchPanelId;
    private string $switchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteManager   = new SiteManager();
        $this->rackManager   = new RackManager();
        $this->deviceManager = new DeviceManager();
        $this->cableManager  = new CableManager();
        $this->pm            = new PathMaterializer();
    }

    protected function tearDown(): void
    {
        $db = MongoDB::getInstance();
        $db->selectCollection('sites')->deleteMany(['name' => ['$regex' => '^IntTest-']]);
        $db->selectCollection('racks')->deleteMany(['name' => ['$regex' => '^IntTest-']]);
        $db->selectCollection('devices')->deleteMany(['name' => ['$regex' => '^IntTest-']]);
        $db->selectCollection('cables')->deleteMany(['cable_id' => ['$regex' => '^C-INT-']]);
        $db->selectCollection('connectivity_paths')->deleteMany([]);
        parent::tearDown();
    }

    // ─── Step 1–3: Site → Rack → Device ──────────────────────────────────────

    public function testCreateSite(): void
    {
        $id = $this->siteManager->create([
            'name' => 'IntTest-DC1',
            'code' => 'INTTEST',
            'type' => 'datacenter',
            'address' => [
                'city' => 'Amsterdam',
                'country' => 'NL',
                'coordinates' => ['lat' => 52.37, 'lng' => 4.90],
            ],
        ]);

        $this->assertNotEmpty($id);

        $site = $this->siteManager->findById($id);
        $this->assertEquals('IntTest-DC1', $site['name']);
        $this->assertEquals('INTTEST', $site['code']);
        $this->assertEquals('datacenter', $site['type']);
        $this->assertEquals(52.37, $site['address']['coordinates']['lat']);

        $this->siteId = $id;
        return; // phpunit doesn't support returning from test — set as property
    }

    // ─── Full end-to-end ──────────────────────────────────────────────────────

    public function testFullInfrastructureLifecycle(): void
    {
        // 1. Create site
        $this->siteId = $this->siteManager->create([
            'name' => 'IntTest-DC1-' . uniqid(),
            'code' => 'IT' . strtoupper(substr(uniqid(), -4)),
            'type' => 'datacenter',
        ]);
        $this->assertNotEmpty($this->siteId);

        // 2. Create rack
        $this->rackId = $this->rackManager->create([
            'site_id' => $this->siteId,
            'name'    => 'IntTest-R01',
            'specs'   => ['total_units' => 42, 'usable_units' => 40, 'max_power_watts' => 8000],
        ]);
        $this->assertNotEmpty($this->rackId);

        $rack = $this->rackManager->findById($this->rackId);
        $this->assertEquals('IntTest-R01', $rack['name']);
        $this->assertEquals(42, $rack['specs']['total_units']);

        // 3. Create server
        $this->serverId = $this->deviceManager->create([
            'name'     => 'IntTest-Server01',
            'vendor'   => 'dell',
            'role'     => 'server',
            'site_id'  => $this->siteId,
            'rack_id'  => $this->rackId,
            'rack_unit'=> 10,
            'ports'    => [['name' => 'eth0', 'type' => 'ethernet']],
        ], 'test');
        $this->assertNotEmpty($this->serverId);

        // 4. Create patch panel
        $this->patchPanelId = $this->deviceManager->create([
            'name'    => 'IntTest-PP01',
            'vendor'  => 'generic',
            'role'    => 'patch_panel',
            'site_id' => $this->siteId,
            'rack_id' => $this->rackId,
            'ports'   => [
                ['name' => 'PP-01', 'type' => 'rj45', 'front_label' => 'F-01', 'rear_label' => 'R-01'],
            ],
        ], 'test');
        $this->assertNotEmpty($this->patchPanelId);

        $pp = $this->deviceManager->findById($this->patchPanelId);
        $this->assertEquals('patch_panel', $pp['role']);

        // 5. Create switch
        $this->switchId = $this->deviceManager->create([
            'name'    => 'IntTest-SW01',
            'vendor'  => 'generic',
            'role'    => 'access_switch',
            'site_id' => $this->siteId,
            'rack_id' => $this->rackId,
            'ports'   => [['name' => 'ge-0/0/1', 'type' => 'ethernet']],
        ], 'test');
        $this->assertNotEmpty($this->switchId);

        // 6. Cable: server:eth0 → PP:F-01
        $cable1Id = $this->cableManager->create([
            'cable_id'   => 'C-INT-001',
            'cable_type' => 'cat6a',
            'endpoint_a' => ['device_id' => $this->serverId,     'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->patchPanelId, 'port_name' => 'F-01'],
        ], 'test');
        $this->assertNotEmpty($cable1Id);

        // 7. Cable: PP:R-01 → switch:ge-0/0/1
        $cable2Id = $this->cableManager->create([
            'cable_id'   => 'C-INT-002',
            'cable_type' => 'cat6a',
            'endpoint_a' => ['device_id' => $this->patchPanelId, 'port_name' => 'R-01'],
            'endpoint_b' => ['device_id' => $this->switchId,     'port_name' => 'ge-0/0/1'],
        ], 'test');
        $this->assertNotEmpty($cable2Id);

        // 8. Trace path from server:eth0
        $path = $this->pm->getPath($this->serverId, 'eth0');

        $this->assertNotEmpty($path, 'Expected a traced path from server:eth0');
        $this->assertArrayHasKey('hops', $path);
        $this->assertCount(3, $path['hops']);

        // 9. Verify source and destination
        $this->assertEquals('eth0', $path['source']['port_name']);
        $this->assertEquals('ge-0/0/1', $path['destination']['port_name']);

        // Patch panel hop verification
        $ppHop = $path['hops'][1];
        $this->assertEquals('F-01', $ppHop['port_in']);
        $this->assertEquals('R-01', $ppHop['port_out']);

        // Cable IDs in path
        $this->assertContains('C-INT-001', $path['cable_ids']);
        $this->assertContains('C-INT-002', $path['cable_ids']);
    }

    // ─── Rack utilization ─────────────────────────────────────────────────────

    public function testRackUtilizationIsTracked(): void
    {
        $siteId = $this->siteManager->create([
            'name' => 'IntTest-Util-' . uniqid(),
            'code' => 'IU' . strtoupper(substr(uniqid(), -4)),
        ]);

        $rackId = $this->rackManager->create([
            'site_id' => $siteId,
            'name'    => 'IntTest-RU01',
            'specs'   => ['total_units' => 42, 'usable_units' => 40, 'max_power_watts' => 8000],
        ]);

        $rack = $this->rackManager->findById($rackId);
        $this->assertEquals(0,  $rack['utilization']['used_units']);
        $this->assertEquals(40, $rack['utilization']['available_units']);

        // Add a device to the rack and recalculate
        MongoDB::getInstance()->selectCollection('racks')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($rackId)],
            ['$push' => ['installed_devices' => [
                'device_id'       => new \MongoDB\BSON\ObjectId(),
                'device_name'     => 'Test',
                'rack_unit_start' => 1,
                'rack_unit_end'   => 2,
                'power_draw_watts'=> 500,
            ]]]
        );

        $this->rackManager->recalculateUtilization($rackId);
        $rack = $this->rackManager->findById($rackId);
        $this->assertEquals(2,   $rack['utilization']['used_units']);
        $this->assertEquals(38,  $rack['utilization']['available_units']);
        $this->assertEquals(500, $rack['utilization']['power_used_watts']);
    }

    // ─── Device CRUD ──────────────────────────────────────────────────────────

    public function testDeviceCRUD(): void
    {
        $id = $this->deviceManager->create([
            'name'   => 'IntTest-Dev-' . uniqid(),
            'vendor' => 'mikrotik',
            'role'   => 'core_router',
            'status' => 'online',
            'tags'   => ['production', 'core'],
        ], 'test');

        $device = $this->deviceManager->findById($id);
        $this->assertEquals('mikrotik', $device['vendor']);
        $this->assertEquals('online', $device['status']);

        // Update
        $this->deviceManager->update($id, ['status' => 'maintenance', 'notes' => 'Scheduled maintenance']);
        $device = $this->deviceManager->findById($id);
        $this->assertEquals('maintenance', $device['status']);
        $this->assertEquals('Scheduled maintenance', $device['notes']);

        // Delete
        $deleted = $this->deviceManager->deleteById($id);
        $this->assertTrue($deleted);
        $this->assertNull($this->deviceManager->findById($id));
    }
}
