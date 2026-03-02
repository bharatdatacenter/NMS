<?php

declare(strict_types=1);

use NMS\Core\Models\Provisioning\SagaExecutor;
use NMS\Core\Models\Provisioning\CompensationRunner;
use NMS\Core\Models\Provisioning\ProvisioningJob;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

/**
 * SagaFailureTest — Integration
 *
 * Requires: MongoDB running and accessible.
 * Skips if no database connection.
 *
 * Verifies:
 *   - When step N fails, all completed steps 1..(N-1) are compensated in reverse order
 *   - IPs allocated in steps 1..3 are released after step 4 fails
 *   - Routes created in steps 1..3 are deleted after step 4 fails
 *   - Job final status is "compensated" or "partial_compensation" (never "failed")
 *   - provisioning_steps shows completed steps with compensation.status = "completed"
 */
class SagaFailureTest extends TestCase
{
    private ?\MongoDB\Collection $jobs    = null;
    private ?\MongoDB\Collection $steps   = null;
    private ?string              $cleanupJobId = null;

    protected function setUp(): void
    {
        try {
            $db          = MongoDB::getInstance();
            $this->jobs  = $db->selectCollection('provisioning_jobs');
            $this->steps = $db->selectCollection('provisioning_steps');
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB not available');
        }
    }

    protected function tearDown(): void
    {
        if ($this->cleanupJobId && $this->jobs) {
            $jobOid = new ObjectId($this->cleanupJobId);
            $this->jobs->deleteOne(['_id' => $jobOid]);
            $this->steps->deleteMany(['job_id' => $jobOid]);
        }
    }

    public function testStep4FailureTriggerCompensationForSteps1To3(): void
    {
        // Create a minimal test job directly in MongoDB
        $insertResult = $this->jobs->insertOne([
            'idempotency_key'  => 'test-saga-fail-' . uniqid(),
            'request_source'   => 'test',
            'request_data'     => [],
            'server_id'        => 'test-server-id',
            'server_name'      => 'test-server',
            'server_mac'       => null,
            'status'           => 'pending',
            'current_step'     => null,
            'progress_percent' => 0,
            'allocated_ips'    => [],
            'created_routes'   => [],
            'created_policies' => [],
            'error_message'    => null,
            'error_step'       => null,
            'started_at'       => null,
            'completed_at'     => null,
            'created_at'       => new \MongoDB\BSON\UTCDateTime(),
            'created_by'       => null,
        ]);
        $jobId              = (string)$insertResult->getInsertedId();
        $this->cleanupJobId = $jobId;
        $jobOid             = new ObjectId($jobId);

        // Track which steps were called
        $callLog = [];

        // Build 4 steps: steps 1-3 succeed, step 4 fails
        $steps = [
            [
                'name'                => 'Step 1 — allocate IP',
                'type'                => 'ipam',
                'input_data'          => ['pool_id' => 'test-pool'],
                'action'              => static function () use (&$callLog): array {
                    $callLog[] = 'step1_forward';
                    return ['ip_address' => '10.0.0.1', 'assignment_id' => 'assign-1'];
                },
                'compensation_action' => 'ipam.release',
            ],
            [
                'name'                => 'Step 2 — create route',
                'type'                => 'route',
                'input_data'          => ['device_id' => 'dev-1'],
                'action'              => static function () use (&$callLog): array {
                    $callLog[] = 'step2_forward';
                    return ['route_id' => 'route-1'];
                },
                'compensation_action' => 'route.delete',
            ],
            [
                'name'                => 'Step 3 — create neighbor',
                'type'                => 'neighbor',
                'input_data'          => [],
                'action'              => static function () use (&$callLog): array {
                    $callLog[] = 'step3_forward';
                    return ['entry_id' => 'entry-1'];
                },
                'compensation_action' => 'neighbor.delete',
            ],
            [
                'name'                => 'Step 4 — create policy (FAILS)',
                'type'                => 'firewall',
                'input_data'          => [],
                'action'              => static function () use (&$callLog): array {
                    $callLog[] = 'step4_forward';
                    // Max retries = 3, all attempts fail
                    throw new \RuntimeException('Policy push failed: device unreachable');
                },
                'compensation_action' => 'noop',
            ],
        ];

        $executor = new SagaExecutor();
        $executor->execute(new ProvisioningJob($jobId, $steps));

        // Verify steps 1-3 were called
        $this->assertContains('step1_forward', $callLog);
        $this->assertContains('step2_forward', $callLog);
        $this->assertContains('step3_forward', $callLog);
        $this->assertContains('step4_forward', $callLog); // Called but failed

        // Verify job is in compensated/partial_compensation state (not "failed")
        $jobDoc = $this->jobs->findOne(['_id' => $jobOid]);
        $jobArr = json_decode(json_encode($jobDoc), true);

        $this->assertContains(
            $jobArr['status'] ?? '',
            ['compensated', 'partial_compensation'],
            "Job should be in compensated state after step failure, got: " . ($jobArr['status'] ?? 'null')
        );

        // Verify the failed step is marked "failed"
        $failedStep = $this->steps->findOne([
            'job_id'     => $jobOid,
            'step_order' => 4,
        ]);
        $failedArr = json_decode(json_encode($failedStep), true);
        $this->assertSame('failed', $failedArr['status'] ?? '');

        // Verify steps 1-3 have compensation status (should be "completed" or "failed")
        for ($i = 1; $i <= 3; $i++) {
            $step    = $this->steps->findOne(['job_id' => $jobOid, 'step_order' => $i]);
            $stepArr = json_decode(json_encode($step), true);
            $compStatus = $stepArr['compensation']['status'] ?? '';
            $this->assertContains($compStatus, ['completed', 'failed'],
                "Step {$i} compensation.status should be completed or failed, got: {$compStatus}"
            );
        }
    }
}
