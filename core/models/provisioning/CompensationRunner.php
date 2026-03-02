<?php

declare(strict_types=1);

namespace NMS\Core\Models\Provisioning;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Models\Firewall\ObjectManager;

/**
 * CompensationRunner
 *
 * Reverses all completed provisioning saga steps in reverse order.
 * Called automatically by SagaExecutor on step failure, or manually
 * via POST /api/provision/jobs/{id}/compensate.
 *
 * Compensation rules:
 *   - Steps are reversed newest-first (reverse of execution order)
 *   - Each compensation action uses the idempotency_key to prevent double-execution
 *   - On compensation failure: adds item to manual_intervention_queue, then CONTINUES
 *   - Final job status: "compensated" (all reversed) or "partial_compensation" (some failed)
 *
 * Supported compensation actions:
 *   ipam.release          — release a single IP
 *   ipam.release_batch    — release multiple IPs (params.ip_addresses[])
 *   route.delete          — delete a route by ID
 *   route.delete_batch    — delete multiple routes (params.route_ids[])
 *   neighbor.delete       — delete a neighbor entry by ID
 *   neighbor.delete_batch — delete multiple neighbor entries (params.entry_ids[])
 *   vip.delete            — delete a VIP by ID
 *   vip.delete_batch      — delete multiple VIPs (params.vip_ids[])
 *   policy.delete         — delete a firewall policy by ID
 *   policy.delete_batch   — delete multiple policies (params.policy_ids[])
 *   noop                  — no compensation needed
 */
class CompensationRunner
{
    private \MongoDB\Collection $jobs;
    private \MongoDB\Collection $steps;
    private \MongoDB\Collection $manualQueue;

    public function __construct()
    {
        $db                = MongoDB::getInstance();
        $this->jobs        = $db->selectCollection('provisioning_jobs');
        $this->steps       = $db->selectCollection('provisioning_steps');
        $this->manualQueue = $db->selectCollection('manual_intervention_queue');
    }

    /**
     * Compensate all completed steps for a job in reverse order.
     *
     * @param string $jobId  MongoDB job ObjectId as string
     */
    public function compensate(string $jobId): void
    {
        $jobOid = new ObjectId($jobId);

        $this->jobs->updateOne(
            ['_id' => $jobOid],
            ['$set' => ['status' => 'compensating']]
        );

        // Load all completed steps in REVERSE order (newest first)
        $cursor = $this->steps->find(
            ['job_id' => $jobOid, 'status' => 'completed'],
            ['sort' => ['step_order' => -1]]
        );

        $hasFailure = false;

        foreach ($cursor as $step) {
            $stepId  = (string)($step['_id'] ?? '');
            $stepOid = new ObjectId($stepId);
            $comp    = (array)($step['compensation'] ?? []);
            $action  = (string)($comp['action'] ?? 'noop');
            $params  = (array)($comp['params'] ?? []);

            // Skip if already compensated (idempotent re-run safety)
            if (($comp['status'] ?? '') === 'completed') {
                continue;
            }

            $this->steps->updateOne(
                ['_id' => $stepOid],
                ['$set' => [
                    'compensation.status'       => 'running',
                    'compensation.attempted_at' => new UTCDateTime(),
                ]]
            );

            try {
                $this->dispatch($action, $params);

                $this->steps->updateOne(
                    ['_id' => $stepOid],
                    ['$set' => ['compensation.status' => 'completed']]
                );
            } catch (\Throwable $e) {
                $hasFailure = true;

                $this->steps->updateOne(
                    ['_id' => $stepOid],
                    ['$set' => [
                        'compensation.status' => 'failed',
                        'compensation.error'  => $e->getMessage(),
                    ]]
                );

                // Add to manual intervention queue and continue (do NOT stop)
                $this->addToManualQueue($jobId, $step, $e->getMessage());
            }
        }

        $finalStatus = $hasFailure ? 'partial_compensation' : 'compensated';

        $this->jobs->updateOne(
            ['_id' => $jobOid],
            ['$set' => ['status' => $finalStatus]]
        );
    }

    // ─── Compensation dispatch ────────────────────────────────────────────────

    /**
     * Route to the correct compensation handler based on action string.
     */
    private function dispatch(string $action, array $params): void
    {
        match ($action) {
            'ipam.release'         => $this->releaseIp($params),
            'ipam.release_batch'   => $this->releaseIpBatch($params),
            'route.delete'         => $this->deleteRoute($params),
            'route.delete_batch'   => $this->deleteRouteBatch($params),
            'neighbor.delete'      => $this->deleteNeighbor($params),
            'neighbor.delete_batch'=> $this->deleteNeighborBatch($params),
            'vip.delete'           => $this->deleteVip($params),
            'vip.delete_batch'     => $this->deleteVipBatch($params),
            'policy.delete'        => $this->deletePolicy($params),
            'policy.delete_batch'  => $this->deletePolicyBatch($params),
            'noop'                 => null,       // No compensation needed
            default                => null,       // Unknown action — skip safely
        };
    }

    private function releaseIp(array $params): void
    {
        $ip = (string)($params['ip_address'] ?? '');
        if ($ip === '') {
            return;
        }
        $allocator = new IPAllocator();
        $allocator->release($ip); // Idempotent: returns false if already released
    }

    private function releaseIpBatch(array $params): void
    {
        $allocator = new IPAllocator();
        foreach ((array)($params['ip_addresses'] ?? []) as $ip) {
            if ((string)$ip === '') {
                continue;
            }
            $allocator->release((string)$ip);
        }
    }

    private function deleteRoute(array $params): void
    {
        $routeId = (string)($params['route_id'] ?? '');
        if ($routeId === '') {
            return;
        }
        $manager = new RouteManager();
        try {
            $manager->delete($routeId, 'system-compensation');
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'not found')) {
                return; // Already deleted — idempotent
            }
            throw $e;
        }
    }

    private function deleteRouteBatch(array $params): void
    {
        $manager = new RouteManager();
        foreach ((array)($params['route_ids'] ?? []) as $routeId) {
            if ((string)$routeId === '') {
                continue;
            }
            try {
                $manager->delete((string)$routeId, 'system-compensation');
            } catch (\RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'not found')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function deleteNeighbor(array $params): void
    {
        $entryId = (string)($params['entry_id'] ?? '');
        if ($entryId === '') {
            return;
        }
        $manager = new NeighborManager();
        try {
            $manager->delete($entryId);
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'not found')) {
                return;
            }
            throw $e;
        }
    }

    private function deleteNeighborBatch(array $params): void
    {
        $manager = new NeighborManager();
        foreach ((array)($params['entry_ids'] ?? []) as $entryId) {
            if ((string)$entryId === '') {
                continue;
            }
            try {
                $manager->delete((string)$entryId);
            } catch (\RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'not found')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function deleteVip(array $params): void
    {
        $vipId = (string)($params['vip_id'] ?? '');
        if ($vipId === '') {
            return;
        }
        $manager = new ObjectManager();
        try {
            $manager->deleteVip($vipId);
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'not found')) {
                return;
            }
            throw $e;
        }
    }

    private function deleteVipBatch(array $params): void
    {
        $manager = new ObjectManager();
        foreach ((array)($params['vip_ids'] ?? []) as $vipId) {
            if ((string)$vipId === '') {
                continue;
            }
            try {
                $manager->deleteVip((string)$vipId);
            } catch (\RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'not found')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function deletePolicy(array $params): void
    {
        $policyId = (string)($params['policy_id'] ?? '');
        if ($policyId === '') {
            return;
        }
        $manager = new PolicyManager();
        try {
            $manager->delete($policyId, 'system-compensation');
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'not found')) {
                return;
            }
            throw $e;
        }
    }

    private function deletePolicyBatch(array $params): void
    {
        $manager = new PolicyManager();
        foreach ((array)($params['policy_ids'] ?? []) as $policyId) {
            if ((string)$policyId === '') {
                continue;
            }
            try {
                $manager->delete((string)$policyId, 'system-compensation');
            } catch (\RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'not found')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    // ─── Manual intervention queue ────────────────────────────────────────────

    private function addToManualQueue(string $jobId, mixed $step, string $reason): void
    {
        $stepArr  = json_decode(json_encode($step), true);
        $stepName = (string)($stepArr['step_name'] ?? '');
        $stepNum  = (int)($stepArr['step_order'] ?? 0);
        $compAct  = (string)($stepArr['compensation']['action'] ?? 'unknown');
        $deviceId = (string)($stepArr['input_data']['device_id'] ?? '');

        $queueDoc = [
            'job_id'          => new ObjectId($jobId),
            'step_id'         => isset($stepArr['_id']['$oid']) ? new ObjectId($stepArr['_id']['$oid']) : null,
            'step_order'      => $stepNum,
            'step_name'       => $stepName,
            'device_id'       => $deviceId !== '' ? new ObjectId($deviceId) : null,
            'cluster_id'      => null,
            'action_required' => sprintf(
                'Manually reverse step %d (%s) — action: %s',
                $stepNum, $stepName, $compAct
            ),
            'context'         => $stepArr,
            'reason'          => $reason,
            'ims_ticket_id'   => null,
            'assigned_to'     => null,
            'status'          => 'open',
            'created_at'      => new UTCDateTime(),
            'resolved_at'     => null,
            'resolved_by'     => null,
        ];

        $insertResult = $this->manualQueue->insertOne($queueDoc);
        $queueItemId  = (string)$insertResult->getInsertedId();

        // Attempt to create an IMS ticket for the manual intervention item
        try {
            $this->createImsTicket($jobId, $queueItemId, $queueDoc);
        } catch (\Throwable) {
            // Non-critical: ticket creation failure must not block compensation
        }
    }

    /**
     * Create an IMS ticket for a manual intervention queue item and store the ticket ID.
     */
    private function createImsTicket(string $jobId, string $queueItemId, array $item): void
    {
        // Bootstrap dependencies without DI container
        $config    = require dirname(__DIR__, 3) . '/core/config/app.php';
        $imsUrl    = (string)($config['ims']['api_url'] ?? '');
        if ($imsUrl === '') {
            return; // IMS not configured — skip ticket creation
        }

        $secrets   = new \NMS\Core\Models\Secrets\VaultSecretsManager();
        $jwtHelper = new \NMS\Core\Auth\JWTHelper($secrets);
        $m2mHelper = new \NMS\Core\Auth\M2MTokenHelper($jwtHelper, $config);
        $client    = new \NMS\Core\Auth\ImsTicketClient($m2mHelper, $config);

        $title = sprintf(
            'Provisioning compensation failed — job %s, step %d (%s)',
            $jobId,
            $item['step_order'],
            $item['step_name']
        );
        $body = sprintf(
            "Step %d (%s) compensation failed: %s\n\nManual action required: %s",
            $item['step_order'],
            $item['step_name'],
            $item['reason'],
            $item['action_required']
        );

        $ticketId = $client->createTicket(
            'nms_intervention',
            $title,
            $body,
            ['job_id' => $jobId, 'queue_item_id' => $queueItemId]
        );

        $this->manualQueue->updateOne(
            ['_id' => new ObjectId($queueItemId)],
            ['$set' => ['ims_ticket_id' => $ticketId]]
        );
    }
}
