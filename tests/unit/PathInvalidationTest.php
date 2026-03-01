<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Models\Topology\PathMaterializer;
use NMS\Core\Models\Infrastructure\CableManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Database\MongoDB;

/**
 * Unit tests for path invalidation.
 *
 * Verifies: modifying or deleting a cable sets affected connectivity_paths.valid = false.
 */
class PathInvalidationTest extends TestCase
{
    private DeviceManager    $dm;
    private CableManager     $cm;
    private PathMaterializer $pm;

    private string $deviceAId;
    private string $deviceBId;
    private string $cableId;
    private string $cableLabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dm = new DeviceManager();
        $this->cm = new CableManager();
        $this->pm = new PathMaterializer();

        // Create two devices and one cable between them
        $this->deviceAId = $this->dm->create([
            'name'   => 'PITestA-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
        ], 'test');

        $this->deviceBId = $this->dm->create([
            'name'   => 'PITestB-' . uniqid(),
            'vendor' => 'generic',
            'role'   => 'access_switch',
        ], 'test');

        $this->cableLabel = 'C-PI-' . uniqid();

        $this->cableId = $this->cm->create([
            'cable_id'   => $this->cableLabel,
            'endpoint_a' => ['device_id' => $this->deviceAId, 'port_name' => 'eth0'],
            'endpoint_b' => ['device_id' => $this->deviceBId, 'port_name' => 'eth0'],
        ], 'test');

        // Materialize the path
        $this->pm->materialize($this->deviceAId, 'eth0');
    }

    protected function tearDown(): void
    {
        $db = MongoDB::getInstance();
        $db->selectCollection('devices')->deleteMany(['name' => ['$regex' => '^PITest[AB]-']]);
        $db->selectCollection('cables')->deleteMany(['cable_id' => ['$regex' => '^C-PI-']]);
        $db->selectCollection('connectivity_paths')->deleteMany([]);
        parent::tearDown();
    }

    // ─── Invalidation on cable update ─────────────────────────────────────────

    public function testModifyingCableInvalidatesPath(): void
    {
        // Verify path exists and is valid before update
        $db     = MongoDB::getInstance();
        $before = $db->selectCollection('connectivity_paths')->findOne([
            'cable_ids' => $this->cableLabel,
            'valid'     => true,
        ]);
        $this->assertNotNull($before, 'Path should be valid before modification');

        // Update the cable (change color — triggers invalidation)
        $this->cm->update($this->cableId, ['color' => 'red']);

        // Path should now be invalid
        $after = $db->selectCollection('connectivity_paths')->findOne([
            'cable_ids' => $this->cableLabel,
        ]);

        $this->assertNotNull($after, 'Path document should still exist after update');
        $this->assertFalse((bool)$after['valid'], 'Path should be marked valid=false after cable update');
    }

    // ─── Invalidation on cable delete ─────────────────────────────────────────

    public function testDeletingCableInvalidatesPath(): void
    {
        $db     = MongoDB::getInstance();
        $before = $db->selectCollection('connectivity_paths')->findOne([
            'cable_ids' => $this->cableLabel,
            'valid'     => true,
        ]);
        $this->assertNotNull($before, 'Path should be valid before deletion');

        $this->cm->delete($this->cableId);

        $after = $db->selectCollection('connectivity_paths')->findOne([
            'cable_ids' => $this->cableLabel,
        ]);

        $this->assertNotNull($after, 'Path document should still exist after deletion');
        $this->assertFalse((bool)$after['valid'], 'Path should be marked valid=false after cable deletion');
    }

    // ─── Explicit invalidation API ────────────────────────────────────────────

    public function testExplicitInvalidationSetsValidFalse(): void
    {
        $count = $this->pm->invalidateForCable($this->cableLabel);
        $this->assertGreaterThanOrEqual(1, $count, 'At least one path should be invalidated');

        $db   = MongoDB::getInstance();
        $path = $db->selectCollection('connectivity_paths')->findOne([
            'cable_ids' => $this->cableLabel,
        ]);

        $this->assertNotNull($path);
        $this->assertFalse((bool)$path['valid']);
    }

    // ─── Impact analysis ──────────────────────────────────────────────────────

    public function testGetImpactedPathsReturnsCorrectPaths(): void
    {
        $impacted = $this->pm->getImpactedPaths($this->cableLabel);
        $this->assertCount(1, $impacted, 'One path should be impacted by this cable');
        $this->assertContains($this->cableLabel, $impacted[0]['cable_ids']);
    }
}
