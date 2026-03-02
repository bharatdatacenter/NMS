<?php

declare(strict_types=1);

/**
 * POST /api/provision/deprovision
 *
 * Deprovisions a server's network configuration (reverses provisioning saga).
 * Requires X-Idempotency-Key header.
 *
 * Body: { server_id }
 */

use NMS\Core\Models\Provisioning\ProvisioningEngine;
use NMS\Core\Models\Provisioning\SagaExecutor;
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
    $job    = $engine->deprovisionServer(
        (string)$body['server_id'],
        $idempotencyKey,
        $user['id'] ?? 'system'
    );

    if (!empty($job->steps)) {
        $executor = new SagaExecutor();
        $executor->execute($job);
    }

    Response::json([
        'success'            => true,
        'provisioning_job_id'=> $job->jobId,
        'message'            => 'Deprovisioning started',
    ], 202);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Deprovisioning failed: ' . $e->getMessage(), 500);
}
