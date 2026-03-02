<?php

declare(strict_types=1);

namespace NMS\Core\Models\Provisioning;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * SagaExecutor
 *
 * Orchestrates forward execution of provisioning saga steps in order.
 * After each successful step, populates compensation.params from output_data
 * so CompensationRunner can reverse the step without losing context.
 *
 * On step failure after max_retries:
 *   - Marks step status = "failed"
 *   - Marks job status  = "failed"
 *   - Triggers CompensationRunner for all completed steps
 *
 * All step progress is written to provisioning_steps collection in real-time.
 */
class SagaExecutor
{
    private \MongoDB\Collection $jobs;
    private \MongoDB\Collection $steps;

    private const MAX_RETRIES = 3;

    public function __construct()
    {
        $db          = MongoDB::getInstance();
        $this->jobs  = $db->selectCollection('provisioning_jobs');
        $this->steps = $db->selectCollection('provisioning_steps');
    }

    /**
     * Execute all saga steps in order for the given ProvisioningJob.
     * On any step failure, triggers compensation and returns.
     */
    public function execute(ProvisioningJob $job): void
    {
        $jobOid = new ObjectId($job->jobId);
        $total  = count($job->steps);

        $this->jobs->updateOne(
            ['_id' => $jobOid],
            ['$set' => ['status' => 'running', 'started_at' => new UTCDateTime()]]
        );

        foreach ($job->steps as $idx => $stepDef) {
            $stepOrder = $idx + 1;

            // Insert step record into provisioning_steps
            $insertResult = $this->steps->insertOne([
                'job_id'         => $jobOid,
                'step_order'     => $stepOrder,
                'step_name'      => $stepDef['name'],
                'step_type'      => $stepDef['type'],
                'status'         => 'running',
                'input_data'     => $stepDef['input_data'] ?? [],
                'output_data'    => null,
                'executed_at'    => new UTCDateTime(),
                'compensation'   => [
                    'action'          => $stepDef['compensation_action'],
                    'params'          => [],
                    'idempotency_key' => null,
                    'status'          => 'pending',
                    'attempted_at'    => null,
                    'error'           => null,
                ],
                'error_message'  => null,
                'retry_count'    => 0,
                'max_retries'    => self::MAX_RETRIES,
            ]);
            $stepId = (string) $insertResult->getInsertedId();

            // Update job progress
            $this->jobs->updateOne(
                ['_id' => $jobOid],
                ['$set' => [
                    'current_step'     => $stepOrder,
                    'progress_percent' => (int)(($idx / $total) * 100),
                ]]
            );

            // ── Forward execution with exponential-backoff retries ──────────
            $outputData = null;
            $lastError  = null;
            $retryCount = 0;
            $action     = $stepDef['action'];

            for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $outputData = $action($stepDef['input_data'] ?? []);
                    $lastError  = null;
                    break;
                } catch (\Throwable $e) {
                    $lastError  = $e;
                    $retryCount = $attempt;
                    if ($attempt < self::MAX_RETRIES) {
                        $delayMs = min(500 * (2 ** $attempt) + random_int(0, 200), 5000);
                        usleep($delayMs * 1000);
                    }
                }
            }

            if ($lastError !== null) {
                // Step failed after all retries — record and trigger compensation
                $this->steps->updateOne(
                    ['_id' => new ObjectId($stepId)],
                    ['$set' => [
                        'status'        => 'failed',
                        'retry_count'   => $retryCount,
                        'error_message' => $lastError->getMessage(),
                    ]]
                );
                $this->jobs->updateOne(
                    ['_id' => $jobOid],
                    ['$set' => [
                        'status'        => 'failed',
                        'error_message' => "Step {$stepOrder} ({$stepDef['name']}) failed: " . $lastError->getMessage(),
                        'error_step'    => $stepOrder,
                    ]]
                );

                $runner = new CompensationRunner();
                $runner->compensate($job->jobId);
                return;
            }

            // ── Step succeeded — record output and populate compensation.params ──
            $compParams     = array_merge($stepDef['input_data'] ?? [], $outputData ?? []);
            $idempotencyKey = "comp-{$job->jobId}-step-{$stepOrder}";

            $this->steps->updateOne(
                ['_id' => new ObjectId($stepId)],
                ['$set' => [
                    'status'                       => 'completed',
                    'output_data'                  => $outputData ?? [],
                    'retry_count'                  => $retryCount,
                    'compensation.params'          => $compParams,
                    'compensation.idempotency_key' => $idempotencyKey,
                ]]
            );
        }

        // ── All steps completed ──────────────────────────────────────────────
        $this->jobs->updateOne(
            ['_id' => $jobOid],
            ['$set' => [
                'status'           => 'completed',
                'current_step'     => null,
                'progress_percent' => 100,
                'completed_at'     => new UTCDateTime(),
            ]]
        );
    }
}
