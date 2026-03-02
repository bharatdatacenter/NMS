<?php

declare(strict_types=1);

/**
 * GET /api/provision/jobs/{id}
 *
 * Show a single provisioning job with all its steps and compensation status.
 */

use NMS\Core\Database\MongoDB;
use MongoDB\BSON\ObjectId;
use NMS\Core\Helpers\Response;

try {
    $jobId = (string)($params['id'] ?? '');
    if ($jobId === '') {
        Response::notFound('Job ID is required');
    }

    $db  = MongoDB::getInstance();
    $doc = $db->selectCollection('provisioning_jobs')->findOne(['_id' => new ObjectId($jobId)]);

    if ($doc === null) {
        Response::notFound("Provisioning job {$jobId} not found");
    }

    $arr = json_decode(json_encode($doc), true);
    if (isset($arr['_id']['$oid'])) {
        $arr['id'] = $arr['_id']['$oid'];
        unset($arr['_id']);
    }

    // Load all steps for this job
    $stepsCursor = $db->selectCollection('provisioning_steps')->find(
        ['job_id' => new ObjectId($jobId)],
        ['sort' => ['step_order' => 1]]
    );

    $steps = [];
    foreach ($stepsCursor as $step) {
        $s = json_decode(json_encode($step), true);
        if (isset($s['_id']['$oid'])) {
            $s['id'] = $s['_id']['$oid'];
            unset($s['_id']);
        }
        unset($s['job_id']); // Redundant in this context
        $steps[] = $s;
    }

    $arr['steps'] = $steps;

    Response::json(['data' => $arr]);

} catch (Response) {
    // Already sent
} catch (\InvalidArgumentException $e) {
    Response::notFound($e->getMessage());
} catch (\Exception $e) {
    Response::error('Failed to retrieve job: ' . $e->getMessage(), 500);
}
