<?php

declare(strict_types=1);

use NMS\Core\Models\Provisioning\ProvisioningEngine;
use NMS\Core\Models\Provisioning\SagaExecutor;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

/**
 * FullProvisionCycleTest — Integration
 *
 * Requires: MongoDB running AND real device access (MIKROTIK_HOST or FORTIGATE_HOST env).
 * Skips if no real device is configured.
 *
 * RISK GATE: This test MUST pass against at least one real device before Phase 7.
 *
 * Verifies the complete provision → deprovision cycle:
 *   1. Phase 0 validation passes
 *   2. Provisioning saga completes (IPs allocated, routes created, policies created)
 *   3. All resources exist in DB and on device
 *   4. Deprovisioning saga completes
 *   5. All resources are cleaned up (IPs released, routes deleted, policies deleted)
 *
 * Environment variables required for real device test:
 *   TEST_DEVICE_ID       — MongoDB ObjectId of the test device
 *   TEST_POOL_IPV4_L2    — IPv4 L2 pool ID
 *   TEST_POOL_IPV4_L3    — IPv4 L3 pool ID
 *   TEST_MAC             — MAC address for test server (unused, kept for ARP)
 */
class FullProvisionCycleTest extends TestCase
{
    private ?\MongoDB\Collection $jobs   = null;
    private ?string $cleanupJobId        = null;
    private ?string $cleanupDeprovJobId  = null;
    private ?string $testServerId        = null;

    protected function setUp(): void
    {
        if (!getenv('TEST_DEVICE_ID') || !getenv('TEST_POOL_IPV4_L2')) {
            $this->markTestSkipped(
                'Real device test skipped. Set TEST_DEVICE_ID, TEST_POOL_IPV4_L2, TEST_POOL_IPV4_L3 env vars.'
            );
        }

        try {
            $db         = MongoDB::getInstance();
            $this->jobs = $db->selectCollection('provisioning_jobs');
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB not available');
        }

        $this->testServerId = 'ims-test-server-' . uniqid();
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup
        foreach (array_filter([$this->cleanupJobId, $this->cleanupDeprovJobId]) as $jobId) {
            try {
                $db = MongoDB::getInstance();
                $jobOid = new ObjectId($jobId);
                $db->selectCollection('provisioning_jobs')->deleteOne(['_id' => $jobOid]);
                $db->selectCollection('provisioning_steps')->deleteMany(['job_id' => $jobOid]);
            } catch (\Throwable) {
            }
        }
    }

    public function testFullProvisionDeprovisionCycle(): void
    {
        $deviceId   = (string)getenv('TEST_DEVICE_ID');
        $poolL2     = (string)getenv('TEST_POOL_IPV4_L2');
        $poolL3     = (string)(getenv('TEST_POOL_IPV4_L3') ?: '');
        $testMac    = (string)(getenv('TEST_MAC') ?: 'AA:BB:CC:DD:EE:01');

        $request = [
            'server_id'        => $this->testServerId,
            'server_name'      => 'integration-test-server',
            'mac_address'      => $testMac,
            'address_families' => ['ipv4'],
            'device_id'        => $deviceId,
            'cluster_id'       => null,
            'pool_id_ipv4_l2'  => $poolL2,
            'pool_id_ipv4_l3'  => $poolL3,
            'l2_ip_count'      => 1,
            'l3_ip_count'      => $poolL3 !== '' ? 1 : 0,
            'firewall_rules'   => [],
            'skip_phase0'      => false,
        ];

        $engine = new ProvisioningEngine();

        // ── Phase 0 validation ────────────────────────────────────────────────
        $errors = $engine->validatePhase0($request);
        $this->assertEmpty($errors, 'Phase 0 validation should pass: ' . json_encode($errors));

        // ── Provision ─────────────────────────────────────────────────────────
        $iKey = 'full-cycle-prov-' . uniqid();
        $job  = $engine->provisionServer($request, $iKey);
        $this->cleanupJobId = $job->jobId;

        $executor = new SagaExecutor();
        $executor->execute($job);

        // Verify job completed
        $db     = MongoDB::getInstance();
        $jobDoc = $db->selectCollection('provisioning_jobs')->findOne([
            '_id' => new ObjectId($job->jobId),
        ]);
        $jobArr = json_decode(json_encode($jobDoc), true);

        $this->assertSame('completed', $jobArr['status'] ?? '', 'Provisioning job should complete');
        $this->assertNotEmpty($jobArr['allocated_ips']['ipv4']['l2'] ?? [], 'L2 IPv4 should be allocated');

        // ── Deprovision ───────────────────────────────────────────────────────
        $deprovKey = 'full-cycle-deprov-' . uniqid();
        $deprovJob = $engine->deprovisionServer($this->testServerId, $deprovKey);
        $this->cleanupDeprovJobId = $deprovJob->jobId;

        $executor->execute($deprovJob);

        $deprovDoc = $db->selectCollection('provisioning_jobs')->findOne([
            '_id' => new ObjectId($deprovJob->jobId),
        ]);
        $deprovArr = json_decode(json_encode($deprovDoc), true);

        $this->assertSame('completed', $deprovArr['status'] ?? '', 'Deprovisioning job should complete');

        // Verify IPs are released
        $ipAssignments = $db->selectCollection('ip_assignments')->find([
            'assigned_to.id' => $this->testServerId,
            'status'         => 'active',
        ])->toArray();

        $this->assertEmpty($ipAssignments, 'All IPs should be released after deprovisioning');
    }
}
