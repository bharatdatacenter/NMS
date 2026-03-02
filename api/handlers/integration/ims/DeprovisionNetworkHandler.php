<?php

declare(strict_types=1);

/**
 * POST /api/integration/ims/deprovision-network
 *
 * IMS-facing endpoint: triggers network deprovisioning when a server is decommissioned.
 * Requires M2M token with aud: "nms-m2m".
 * Requires X-Idempotency-Key header.
 *
 * Body: { server_id }
 */

use NMS\Core\Models\Provisioning\ProvisioningEngine;
use NMS\Core\Models\Provisioning\SagaExecutor;
use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;

try {
    $idempotencyKey = $request['headers']['X-Idempotency-Key']
                   ?? $request['headers']['x-idempotency-key']
                   ?? '';
    if ($idempotencyKey === '') {
        Response::json(['success' => false, 'message' => 'X-Idempotency-Key header is required'], 422);
    }

    $v = new Validator();
    $v->validate($body, ['server_id' => 'required|string']);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $engine = new ProvisioningEngine();
    $job    = $engine->deprovisionServer((string)$body['server_id'], $idempotencyKey, 'ims-service');

    $isDuplicate = empty($job->steps);

    if (!$isDuplicate) {
        $executor = new SagaExecutor();
        $executor->execute($job);
    }

    $db     = MongoDB::getInstance();
    $jobDoc = $db->selectCollection('provisioning_jobs')->findOne(['_id' => new ObjectId($job->jobId)]);
    $jobArr = json_decode(json_encode($jobDoc), true);

    Response::json([
        'success'             => true,
        'provisioning_job_id' => $job->jobId,
        'status'              => $jobArr['status'] ?? 'running',
        'idempotent_replay'   => $isDuplicate,
    ], 202);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Network deprovisioning failed: ' . $e->getMessage(), 500);
}
