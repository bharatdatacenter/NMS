<?php

declare(strict_types=1);

/**
 * POST /api/integration/ims/provision-network
 *
 * IMS-facing endpoint: triggers network provisioning for a newly deployed server.
 * Requires M2M token with aud: "nms-m2m" and permission: nms.provision.execute.
 * Requires X-Idempotency-Key header.
 *
 * Body mirrors Section 11.3 of NMS-PLAN:
 * {
 *   server_id, server_name, mac_address, datacenter, rack_id?, rack_unit?,
 *   address_families[], l2_ip_count, l3_ip_count, firewall_rules[],
 *   device_id, cluster_id?, pool_id_ipv4_l2?, pool_id_ipv4_l3?,
 *   pool_id_ipv6_l2?, pool_id_ipv6_l3?
 * }
 *
 * Returns: { success, provisioning_job_id, allocated }
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
    $v->validate($body, [
        'server_id'       => 'required|string',
        'mac_address'     => 'required|string',
        'address_families'=> 'required',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $body['request_source'] = 'ims_api';

    $engine = new ProvisioningEngine();

    // Phase 0 pre-validation
    $errors = $engine->validatePhase0($body);
    if (!empty($errors)) {
        Response::json([
            'success'    => false,
            'message'    => 'Pre-validation failed — no changes made',
            'validation' => $errors,
        ], 422);
    }

    $job = $engine->provisionServer($body, $idempotencyKey, 'ims-service');

    // Check if this is a duplicate idempotency key (returns job with empty steps)
    $isDuplicate = empty($job->steps);

    if (!$isDuplicate) {
        $executor = new SagaExecutor();
        $executor->execute($job);
    }

    // Load job result for response
    $db     = MongoDB::getInstance();
    $jobDoc = $db->selectCollection('provisioning_jobs')->findOne(['_id' => new ObjectId($job->jobId)]);
    $jobArr = json_decode(json_encode($jobDoc), true);

    Response::json([
        'success'             => true,
        'provisioning_job_id' => $job->jobId,
        'allocated'           => $jobArr['allocated_ips'] ?? [],
        'status'              => $jobArr['status'] ?? 'running',
        'idempotent_replay'   => $isDuplicate,
    ], 202);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::unprocessable($e->getMessage());
} catch (\Exception $e) {
    Response::error('Network provisioning failed: ' . $e->getMessage(), 500);
}
