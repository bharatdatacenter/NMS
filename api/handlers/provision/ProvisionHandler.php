<?php

declare(strict_types=1);

/**
 * POST /api/provision/server
 *
 * Provisions a server's network configuration (dual-stack saga, 13 steps).
 * Requires X-Idempotency-Key header.
 *
 * Body: {
 *   server_id, server_name, mac_address, address_families[],
 *   device_id, cluster_id?, pool_id_ipv4_l2?, pool_id_ipv4_l3?,
 *   pool_id_ipv6_l2?, pool_id_ipv6_l3?, l2_ip_count, l3_ip_count,
 *   firewall_rules[], skip_phase0?
 * }
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
    $v->validate($body, [
        'server_id'       => 'required|string',
        'mac_address'     => 'required|string',
        'address_families'=> 'required',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $engine = new ProvisioningEngine();

    // Phase 0 — Pre-validation (unless explicitly skipped for testing)
    if (empty($body['skip_phase0'])) {
        $errors = $engine->validatePhase0($body);
        if (!empty($errors)) {
            Response::json([
                'success'    => false,
                'message'    => 'Pre-validation failed',
                'validation' => $errors,
            ], 422);
        }
    }

    // Phase 1 — Build saga job
    $job = $engine->provisionServer(
        $body,
        $idempotencyKey,
        $user['id'] ?? 'system'
    );

    // Execute synchronously (long-running jobs should be queued in production)
    if (!empty($job->steps)) {
        $executor = new SagaExecutor();
        $executor->execute($job);
    }

    Response::json([
        'success'            => true,
        'provisioning_job_id'=> $job->jobId,
        'message'            => 'Provisioning started',
    ], 202);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Provisioning failed: ' . $e->getMessage(), 500);
}
