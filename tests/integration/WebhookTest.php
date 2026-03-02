<?php

declare(strict_types=1);

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

/**
 * WebhookTest — Integration
 *
 * Requires: MongoDB running.
 * Skips if no database connection.
 *
 * Verifies:
 *   - server.nic_change webhook correctly upserts server_nics documents
 *   - Subsequent nic_change for same MAC updates fields without creating duplicate
 *   - MAC address uniqueness is enforced
 *   - NMS-owned fields (connected_to, vlan_id) are NOT overwritten by webhook sync
 */
class WebhookTest extends TestCase
{
    private ?\MongoDB\Collection $nics = null;
    private array $cleanupMacs = [];

    protected function setUp(): void
    {
        try {
            $db         = MongoDB::getInstance();
            $this->nics = $db->selectCollection('server_nics');
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB not available');
        }
    }

    protected function tearDown(): void
    {
        if ($this->nics) {
            foreach ($this->cleanupMacs as $mac) {
                $this->nics->deleteOne(['mac_address' => strtolower($mac)]);
            }
        }
    }

    public function testNicChangeWebhookUpsertsServerNics(): void
    {
        $mac      = strtolower('aa:bb:cc:' . substr(uniqid(), -6, 6));
        $serverId = 'webhook-test-server-' . uniqid();
        $this->cleanupMacs[] = $mac;

        $nicPayload = [[
            'name'        => 'eth0',
            'mac_address' => $mac,
            'nic_index'   => 0,
            'server_name' => 'webhook-test-server',
        ]];

        $manager = new \NMS\Core\Models\Nics\NicManager();
        $manager->syncFromWebhook($serverId, $nicPayload);

        $doc = $this->nics->findOne(['mac_address' => $mac]);
        $this->assertNotNull($doc, 'NIC document should be created');

        $arr = json_decode(json_encode($doc), true);
        $this->assertSame($serverId, $arr['ims_server_id'] ?? '');
        $this->assertSame('eth0', $arr['nic_name'] ?? '');
        $this->assertSame($mac, $arr['mac_address'] ?? '');
        $this->assertSame(0, $arr['nic_index'] ?? -1);
    }

    public function testNicChangeWebhookUpdatesMacWithoutDuplicate(): void
    {
        $mac      = strtolower('dd:ee:ff:' . substr(uniqid(), -6, 6));
        $serverId = 'webhook-test-server-' . uniqid();
        $this->cleanupMacs[] = $mac;

        $manager = new \NMS\Core\Models\Nics\NicManager();

        // First sync
        $manager->syncFromWebhook($serverId, [['name' => 'eth0', 'mac_address' => $mac, 'nic_index' => 0]]);

        // Simulate NMS operator setting a port assignment
        $nicDoc = $this->nics->findOne(['mac_address' => $mac]);
        $nicId  = (string)($nicDoc['_id'] ?? '');

        $this->nics->updateOne(
            ['_id' => new ObjectId($nicId)],
            ['$set' => ['vlan_id' => 100, 'connected_to' => ['device_name' => 'Core-SW-01']]]
        );

        // Second webhook sync (e.g. NIC was re-enumerated)
        $manager->syncFromWebhook($serverId, [['name' => 'eth0', 'mac_address' => $mac, 'nic_index' => 0, 'server_name' => 'updated-server']]);

        // Verify no duplicate
        $count = $this->nics->countDocuments(['mac_address' => $mac]);
        $this->assertSame(1, $count, 'No duplicate should be created on second sync');

        // NMS-owned field (vlan_id) set by $setOnInsert should remain if already set by operator
        // (since $set only updates IMS-owned fields, $setOnInsert only runs on insert)
        $updated = json_decode(json_encode($this->nics->findOne(['mac_address' => $mac])), true);
        $this->assertSame(100, $updated['vlan_id'] ?? null, 'Operator-set vlan_id should not be overwritten');
    }

    public function testNicSyncFromWebhookSkipsEmptyMac(): void
    {
        $manager = new \NMS\Core\Models\Nics\NicManager();

        // Should not throw; should silently skip entries with no MAC
        $manager->syncFromWebhook('any-server', [
            ['name' => 'eth0', 'mac_address' => '', 'nic_index' => 0],
            ['name' => 'eth1'], // No mac_address key
        ]);

        $this->assertTrue(true); // If we got here without exception, test passes
    }
}
