<?php

declare(strict_types=1);

namespace NMS\Core\Models\Provisioning;

/**
 * ProvisioningJob — value object passed to SagaExecutor::execute().
 *
 * Holds the MongoDB job ID and the ordered list of step definitions.
 * Each step definition contains:
 *   - name              (string) display name for the step
 *   - type              (string) ipam | route | neighbor | firewall | vip | verify
 *   - input_data        (array)  static parameters used by the step's action callable
 *   - action            (callable) fn(array $inputData): array $outputData
 *   - compensation_action (string) action key used by CompensationRunner
 */
class ProvisioningJob
{
    public string $jobId;

    /** @var array<int, array{name: string, type: string, input_data: array, action: callable, compensation_action: string}> */
    public array $steps;

    public function __construct(string $jobId, array $steps)
    {
        $this->jobId = $jobId;
        $this->steps = $steps;
    }
}
