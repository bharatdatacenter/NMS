<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Database\MongoDB;

/**
 * Unit tests for PathMaterializer.
 *
 * Scenario: server → patch_panel → switch (3-hop path)
 *   cable1: server:eth0 → PP:F-01
 *   cable2: PP:R-01     → switch:ge-0/0/1
 *
 * Expected materialized path:
 *   hops[0]: server, port_out=eth0, cable_id=C-001
 *   hops[1]: PP, port_in=F-01, port_out=R-01, cable_id=C-002
 *   hops[2]: switch, port_in=ge-0/0/1, cable_id=null
 */
class PathMaterializerTest extends TestCase
{
    private DeviceManager $dm;
    private CableManager  $cm;
    private PathMaterializer $pm;

    private string $serverId;
    private string $patchPanelId;
    private string $switchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dm = new DeviceManager();
        $this->cm = new CableManager();
        $this->pm = new PathMaterializer();

        // Create test topology: server → PP → switch
        $this->serverId = $this->dm->create([
            'name'   => 'TestServer-' . uniqid(),
            'vendor' => 'dell',
            'role'   => 'server',
            'ports'  => [['name' => 'eth0', 'type' => 'ethernet']],
        ], 'test');

        $this->patchPanelId = $this->dm->create([
            'name'   => 'TestPP-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'patch_panel',
            'ports'  => [
                ['name' => 'PP-01', 'type' => 'rj45', 'front_label' => 'F-01', 'rear_label' => 'R-01'],
            ],
        ], 'test');

        $this->switchId = $this->dm->create([
            'name'   => 'TestSwitch-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
            'ports'  => [['name' => 'ge-0/0/1', 'type' => 'ethernet']],
        ], 'test');

        // Register cables
        $this->cm->create([
            'cable_id'   => 'C-PM-TEST-001',
            'endpoint_a' => ['device_id' => $this->serverId,     'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->patchPanelId, 'port_name' => 'F-01'],
        ], 'test');

        $this->cm->create([
            'cable_id'   => 'C-PM-TEST-002',
            'endpoint_a' => ['device_id' => $this->patchPanelId, 'port_name' => 'R-01'],
            'endpoint_b' => ['device_id' => $this->switchId,     'port_name' => 'ge-0/0/1'],
        ], 'test');
    }

    protected function tearDown(): void
    {
        $db = MongoDB::getInstance();
        $db->selectCollection('devices')->deleteMany(['name' => ['$regex' => '^Test(Server|PP|Switch)-']]);
        $db->selectCollection('cables')->deleteMany(['cable_id' => ['$regex' => '^C-PM-TEST-']]);
        $db->selectCollection('connectivity_paths')->deleteMany([]);
        parent::tearDown();
    }

    // ─── Path structure ───────────────────────────────────────────────────────

    public function testMaterializesThreeHopPath(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');

        $this->assertNotEmpty($path, 'Expected a materialized path');
        $this->assertArrayHasKey('hops', $path);

        $hops = $path['hops'];
        $this->assertCount(3, $hops, 'Expected 3 hops (server, PP, switch)');
    }

    public function testPathSourceIsServer(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');

        $source = $path['source'];
        $this->assertEquals($this->serverId, $source['device_id']['$oid'] ?? $source['device_id']);
        $this->assertEquals('eth0', $source['port_name']);
    }

    public function testPathDestinationIsSwitch(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');

        $dest = $path['destination'];
        $this->assertEquals($this->switchId, $dest['device_id']['$oid'] ?? $dest['device_id']);
        $this->assertEquals('ge-0/0/1', $dest['port_name']);
    }

    public function testPatchPanelHopHasBothPortInAndPortOut(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');
        $hops = $path['hops'];

        // Middle hop should be the patch panel
        $ppHop = $hops[1];
        $this->assertArrayHasKey('port_in', $ppHop);
        $this->assertArrayHasKey('port_out', $ppHop);
        $this->assertEquals('F-01', $ppHop['port_in']);
        $this->assertEquals('R-01', $ppHop['port_out']);
    }

    public function testCableIdsCollected(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');

        $this->assertContains('C-PM-TEST-001', $path['cable_ids']);
        $this->assertContains('C-PM-TEST-002', $path['cable_ids']);
    }

    public function testHopCountIsTwo(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');
        // hop_count = number of cables traversed (source→PP = 1, PP→switch = 1 = 2)
        $this->assertEquals(2, $path['hop_count']);
    }

    public function testPathIsMarkedValid(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth0');
        $this->assertTrue($path['valid']);
    }

    // ─── Pre-computed path is returned on second call ─────────────────────────

    public function testReturnsPrecomputedPathOnSecondCall(): void
    {
        $path1 = $this->pm->materialize($this->serverId, 'eth0');
        $path2 = $this->pm->materialize($this->serverId, 'eth0');

        // Both calls return the same source/destination
        $this->assertEquals(
            $path1['source']['port_name'],
            $path2['source']['port_name']
        );
        $this->assertEquals(
            $path1['destination']['port_name'] ?? '',
            $path2['destination']['port_name'] ?? ''
        );
    }

    // ─── Port with no cable returns empty ─────────────────────────────────────

    public function testUnconnectedPortReturnsEmpty(): void
    {
        $path = $this->pm->materialize($this->serverId, 'eth99-nonexistent');
        $this->assertEmpty($path);
    }
}
