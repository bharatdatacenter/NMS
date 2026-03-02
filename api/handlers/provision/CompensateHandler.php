<?php

declare(strict_types=1);

/**
 * POST /api/provision/jobs/{id}/compensate
 *
 * Manually trigger compensation saga for a failed provisioning job.
 * Useful when automatic compensation was partially blocked or needs retry.
 */

use NMS\Core\Models\Provisioning\CompensationRunner;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use NMS\Core\Helpers\Response;

try {
    $jobId = (string)($params['id'] ?? '');
    if ($jobId === '') {
        Response::notFound('Job ID is required');
    }

    $db  = MongoDB::getInstance();
    $job = $db->selectCollection('provisioning_jobs')->findOne(['_id' => new ObjectId($jobId)]);
    if ($job === null) {
        Response::notFound("Provisioning job {$jobId} not found");
    }

    $jobArr = json_decode(json_encode($job), true);
    $status = (string)($jobArr['status'] ?? '');

    // Only allow manual compensation on failed or partial_compensation jobs
    if (!in_array($status, ['failed', 'partial_compensation'], true)) {
        Response::json([
            'success' => false,
            'message' => "Cannot compensate job with status '{$status}'. Only failed or partial_compensation jobs can be compensated.",
        ], 409);
    }

    $runner = new CompensationRunner();
    $runner->compensate($jobId);

    // Reload job to get final status
    $updated    = $db->selectCollection('provisioning_jobs')->findOne(['_id' => new ObjectId($jobId)]);
    $updatedArr = json_decode(json_encode($updated), true);

    Response::json([
        'success'    => true,
        'job_id'     => $jobId,
        'status'     => $updatedArr['status'] ?? 'unknown',
        'message'    => 'Compensation saga completed',
    ]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Compensation failed: ' . $e->getMessage(), 500);
}
