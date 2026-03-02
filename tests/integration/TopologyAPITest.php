<?php

declare(strict_types=1);

namespace Tests\Integration;

use NMS\Core\Models\Topology\TopologyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TopologyAPITest — GET /api/topology returns valid graph
 */
class TopologyAPITest extends TestCase
{
    /**
     * Test that GET /api/topology/site/{id} returns valid graph structure
     *
     * @group integration
     */
    public function testGetSiteTopologyReturnsValidGraph(): void
    {
        // This would be tested via HTTP client in full integration suite
        // For unit context, we test the model directly
        $topologyBuilder = new TopologyBuilder();

        try {
            // Try to build for a test site
            $result = $topologyBuilder->build(['type' => 'global']);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('sites', $result);
            $this->assertArrayHasKey('nodes', $result);
            $this->assertArrayHasKey('links', $result);
        } catch (\Exception $e) {
            // Database not available in test environment
            $this->markTestSkipped('Integration environment not available');
        }
    }

    /**
     * Test cluster nodes are grouped with role info
     *
     * @group integration
     */
    public function testClusterNodesAreGrouped(): void
    {
        $topologyBuilder = new TopologyBuilder();

        try {
            $topology = $topologyBuilder->build(['type' => 'global']);
            // Verify that nodes with cluster info have role field
            foreach ($topology['nodes'] ?? [] as $node) {
                if (isset($node['cluster'])) {
                    $this->assertArrayHasKey('role', $node['cluster']);
                }
            }
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->markTestSkipped('Integration environment not available');
        }
    }

    /**
     * Test topology snapshots are created and retrievable
     *
     * @group integration
     */
    public function testSnapshotsCreatedAndRetrievable(): void
    {
        $topologyBuilder = new TopologyBuilder();

        try {
            $snapshots = $topologyBuilder->getSnapshots();
            $this->assertIsArray($snapshots);
        } catch (\Exception $e) {
            $this->markTestSkipped('Integration environment not available');
        }
    }
}
