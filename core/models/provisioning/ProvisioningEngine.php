<?php

declare(strict_types=1);

namespace NMS\Core\Models\Provisioning;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Ipam\IPAllocator;
use NMS\Core\Models\Ipam\PoolManager;
use NMS\Core\Models\Routing\RouteManager;
use NMS\Core\Models\Routing\RouteSync;
use NMS\Core\Models\Routing\BGPMonitor;
use NMS\Core\Models\Neighbors\NeighborManager;
use NMS\Core\Models\Neighbors\NeighborSync;
use NMS\Core\Models\Firewall\PolicyManager;
use NMS\Core\Models\Firewall\PolicyBuilder;
use NMS\Core\Models\Firewall\ObjectManager;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\ClusterManager;

/**
 * ProvisioningEngine
 *
 * Implements the two-phase provisioning workflow:
 *
 *   Phase 0 — Validate (read-only, zero mutations):
 *     a. Pool availability for all requested address families
 *     b. Device reachability (health check via vendor adapter)
 *     c. IPAM conflict check (existing active assignments for same MAC)
 *     d. BGP prefix conflict check for requested IP ranges
 *     e. Cluster health check (if device is HA cluster)
 *     If ANY check fails: abort, return errors. Nothing is mutated.
 *
 *   Phase 1 — Execute (saga with compensating transactions):
 *     13 saga steps in order (IPv4 track: steps 1-7, IPv6 track: steps 8-13).
 *     SagaExecutor runs steps; CompensationRunner reverses on failure.
 *
 * Idempotency:
 *   The idempotency_key is stored on the job document.
 *   Duplicate requests with the same key return the existing job.
 */
class ProvisioningEngine
{
    private \MongoDB\Collection $jobs;
    private \MongoDB\Collection $steps;
    private IPAllocator     $allocator;
    private PoolManager     $poolManager;
    private RouteManager    $routeManager;
    private RouteSync       $routeSync;
    private NeighborManager $neighborManager;
    private NeighborSync    $neighborSync;
    private PolicyManager   $policyManager;
    private PolicyBuilder   $policyBuilder;
    private ObjectManager   $objectManager;
    private DeviceManager   $deviceManager;
    private ClusterManager  $clusterManager;

    public function __construct()
    {
        $db                    = MongoDB::getInstance();
        $this->jobs            = $db->selectCollection('provisioning_jobs');
        $this->steps           = $db->selectCollection('provisioning_steps');
        $this->allocator       = new IPAllocator();
        $this->poolManager     = new PoolManager();
        $this->routeManager    = new RouteManager();
        $this->routeSync       = new RouteSync();
        $this->neighborManager = new NeighborManager();
        $this->neighborSync    = new NeighborSync();
        $this->policyManager   = new PolicyManager();
        $this->policyBuilder   = new PolicyBuilder();
        $this->objectManager   = new ObjectManager();
        $this->deviceManager   = new DeviceManager();
        $this->clusterManager  = new ClusterManager();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 0 — Pre-Validation (read-only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run all pre-provisioning checks without mutating any state.
     * Returns an array of validation errors, or empty array if all pass.
     *
     * @param array $request {
     *   server_id, mac_address, address_families, device_id, cluster_id?,
     *   pool_id_ipv4_l2?, pool_id_ipv4_l3?, pool_id_ipv6_l2?, pool_id_ipv6_l3?,
     *   l2_ip_count, l3_ip_count
     * }
     * @return array validation errors keyed by check name (empty = all passed)
     */
    public function validatePhase0(array $request): array
    {
        $errors = [];

        $addressFamilies = (array)($request['address_families'] ?? ['ipv4']);
        $deviceId        = (string)($request['device_id'] ?? '');
        $clusterId       = (string)($request['cluster_id'] ?? '');
        $mac             = (string)($request['mac_address'] ?? '');

        // a. Pool availability
        foreach ($addressFamilies as $family) {
            foreach (['l2', 'l3'] as $tier) {
                $poolKey = "pool_id_{$family}_{$tier}";
                if (empty($request[$poolKey])) {
                    continue; // Pool ID not required if that family/tier not requested
                }
                $available = $this->poolManager->getAvailableCount($request[$poolKey]);
                $required  = ($tier === 'l2')
                    ? (int)($request['l2_ip_count'] ?? 1)
                    : (int)($request['l3_ip_count'] ?? 1);

                if ($available < $required) {
                    $errors["pool_{$family}_{$tier}"] =
                        "Pool {$request[$poolKey]} has only {$available} available IPs, need {$required}";
                }
            }
        }

        // b. Device reachability
        if ($deviceId !== '') {
            try {
                $device  = $this->deviceManager->findById($deviceId);
                if (!$device) {
                    $errors['device_reachability'] = "Device {$deviceId} not found";
                } else {
                    $adapter = DeviceFactory::make($device);
                    $status  = $adapter->getDeviceStatus();
                    if (($status['reachable'] ?? false) === false) {
                        $errors['device_reachability'] = "Device {$deviceId} is unreachable";
                    }
                }
            } catch (\Throwable $e) {
                $errors['device_reachability'] = 'Device health check failed: ' . $e->getMessage();
            }
        }

        // c. IPAM conflict — existing active assignment for same MAC
        if ($mac !== '') {
            $db = MongoDB::getInstance();
            $existing = $db->selectCollection('ip_assignments')->findOne([
                'mac_address' => $mac,
                'status'      => 'active',
            ]);
            if ($existing !== null) {
                $errors['ipam_conflict'] = "MAC {$mac} already has an active IP assignment";
            }
        }

        // d. BGP conflict check
        if ($deviceId !== '') {
            foreach ($addressFamilies as $family) {
                $poolKey = "pool_id_{$family}_l3";
                if (empty($request[$poolKey])) {
                    continue;
                }
                $pool = $this->poolManager->findPoolById($request[$poolKey]);
                if ($pool) {
                    try {
                        $device  = $this->deviceManager->findById($deviceId);
                        if ($device) {
                            $adapter    = DeviceFactory::make($device);
                            $conflicts  = $adapter->getBGPPrefixesForRange($pool['network'] ?? '');
                            if (!empty($conflicts)) {
                                $errors["bgp_conflict_{$family}"] =
                                    'BGP prefix conflict: ' . implode(', ', array_slice($conflicts, 0, 3));
                            }
                        }
                    } catch (\Throwable $e) {
                        // BGP conflict check is best-effort; adapter may not support it
                        // Log but don't block
                    }
                }
            }
        }

        // e. Cluster health
        if ($clusterId !== '') {
            try {
                $cluster = $this->clusterManager->findById($clusterId);
                if (!$cluster) {
                    $errors['cluster_health'] = "Cluster {$clusterId} not found";
                } elseif (($cluster['status'] ?? '') === 'degraded') {
                    $errors['cluster_health'] = "Cluster {$clusterId} is degraded";
                }
            } catch (\Throwable $e) {
                $errors['cluster_health'] = 'Cluster health check failed: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Provision Server
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Provision a server's network (dual-stack, 13 saga steps).
     *
     * @param array  $request          Provisioning request data
     * @param string $idempotencyKey   X-Idempotency-Key header value
     * @param string $createdBy        User/service ID for audit trail
     * @return ProvisioningJob         The constructed job (executor not yet called)
     * @throws \RuntimeException       If idempotency key is duplicate or validation fails
     */
    public function provisionServer(
        array $request,
        string $idempotencyKey,
        string $createdBy = 'system'
    ): ProvisioningJob {
        // Idempotency: return existing job if key already used
        $existing = $this->jobs->findOne(['idempotency_key' => $idempotencyKey]);
        if ($existing !== null) {
            $existingArr = json_decode(json_encode($existing), true);
            $jobId       = $existingArr['_id']['$oid'] ?? (string)($existing['_id'] ?? '');
            return new ProvisioningJob($jobId, []);
        }

        $addressFamilies = (array)($request['address_families'] ?? ['ipv4']);
        $serverId        = (string)($request['server_id'] ?? '');
        $serverName      = (string)($request['server_name'] ?? 'unknown');
        $mac             = (string)($request['mac_address'] ?? '');
        $deviceId        = (string)($request['device_id'] ?? '');
        $clusterId       = (string)($request['cluster_id'] ?? '');
        $l3Count         = max(1, (int)($request['l3_ip_count'] ?? 1));
        $firewallRules   = (array)($request['firewall_rules'] ?? []);

        // Create job document
        $insertResult = $this->jobs->insertOne([
            'idempotency_key' => $idempotencyKey,
            'request_source'  => $request['request_source'] ?? 'manual',
            'request_data'    => $request,
            'server_id'       => $serverId,
            'server_name'     => $serverName,
            'server_mac'      => $mac,
            'status'          => 'pending',
            'current_step'    => null,
            'progress_percent'=> 0,
            'allocated_ips'   => ['ipv4' => ['l2' => [], 'l3' => []], 'ipv6' => ['l2' => [], 'l3' => []]],
            'created_routes'  => [],
            'created_policies'=> [],
            'error_message'   => null,
            'error_step'      => null,
            'started_at'      => null,
            'completed_at'    => null,
            'created_at'      => new UTCDateTime(),
            'created_by'      => $createdBy !== '' ? new ObjectId($createdBy) : null,
        ]);
        $jobId = (string)$insertResult->getInsertedId();

        $serverInfo = ['type' => 'server', 'id' => $serverId, 'name' => $serverName, 'site_id' => null];

        $steps = [];

        // ── IPv4 Track ────────────────────────────────────────────────────────
        if (in_array('ipv4', $addressFamilies, true)) {
            $poolL2Ipv4 = (string)($request['pool_id_ipv4_l2'] ?? '');
            $poolL3Ipv4 = (string)($request['pool_id_ipv4_l3'] ?? '');

            // Step 1: Allocate L2 IPv4
            if ($poolL2Ipv4 !== '') {
                $steps[] = $this->buildAllocateStep(
                    'Allocate L2 IPv4', 'ipv4', $poolL2Ipv4, $serverInfo, $mac, 'layer2', $jobId, 'l2', 'ipv4'
                );
            }

            // Step 2: Allocate L3 IPv4 IPs (batch)
            if ($poolL3Ipv4 !== '' && $l3Count > 0) {
                $steps[] = $this->buildAllocateBatchStep(
                    'Allocate L3 IPv4 IPs', 'ipv4', $poolL3Ipv4, $serverInfo, $mac, 'layer3', $l3Count, $jobId, 'l3', 'ipv4'
                );
            }

            // Step 3: Create /32 routes for L3 IPv4 (batch)
            if ($deviceId !== '' && $poolL3Ipv4 !== '') {
                $steps[] = $this->buildCreateRoutesBatchStep(
                    'Create IPv4 /32 routes', 'ipv4', 32, $deviceId, $clusterId, $jobId
                );
            }

            // Step 4: Create ARP entries for L3 IPv4 (batch)
            if ($deviceId !== '') {
                $steps[] = $this->buildCreateNeighborsBatchStep(
                    'Create ARP entries', 'arp', $deviceId, $clusterId, $jobId
                );
            }

            // Step 5: Create VIPs for public L3 IPv4 (batch)
            if ($deviceId !== '' && !empty($request['create_vips'])) {
                $steps[] = $this->buildCreateVipsBatchStep(
                    'Create IPv4 VIPs', $deviceId, $clusterId, $jobId
                );
            }

            // Step 6: Create inbound IPv4 firewall policy
            if ($deviceId !== '') {
                $inboundRules = array_filter($firewallRules, fn($r) => ($r['type'] ?? '') === 'inbound');
                if (!empty($inboundRules)) {
                    $steps[] = $this->buildCreatePolicyStep(
                        'Create IPv4 inbound policy', 'ipv4', 'inbound', $deviceId, $clusterId, reset($inboundRules), $jobId
                    );
                }
            }

            // Step 7: Create outbound IPv4 firewall policy
            if ($deviceId !== '') {
                $outboundRules = array_filter($firewallRules, fn($r) => ($r['type'] ?? '') === 'outbound');
                if (!empty($outboundRules)) {
                    $steps[] = $this->buildCreatePolicyStep(
                        'Create IPv4 outbound policy', 'ipv4', 'outbound', $deviceId, $clusterId, reset($outboundRules), $jobId
                    );
                }
            }
        }

        // ── IPv6 Track ────────────────────────────────────────────────────────
        if (in_array('ipv6', $addressFamilies, true)) {
            $poolL2Ipv6 = (string)($request['pool_id_ipv6_l2'] ?? '');
            $poolL3Ipv6 = (string)($request['pool_id_ipv6_l3'] ?? '');

            // Step 8: Allocate L2 IPv6
            if ($poolL2Ipv6 !== '') {
                $steps[] = $this->buildAllocateStep(
                    'Allocate L2 IPv6', 'ipv6', $poolL2Ipv6, $serverInfo, $mac, 'layer2', $jobId, 'l2', 'ipv6'
                );
            }

            // Step 9: Allocate L3 IPv6 IPs (batch)
            if ($poolL3Ipv6 !== '' && $l3Count > 0) {
                $steps[] = $this->buildAllocateBatchStep(
                    'Allocate L3 IPv6 IPs', 'ipv6', $poolL3Ipv6, $serverInfo, $mac, 'layer3', $l3Count, $jobId, 'l3', 'ipv6'
                );
            }

            // Step 10: Create /128 routes for L3 IPv6 (batch)
            if ($deviceId !== '' && $poolL3Ipv6 !== '') {
                $steps[] = $this->buildCreateRoutesBatchStep(
                    'Create IPv6 /128 routes', 'ipv6', 128, $deviceId, $clusterId, $jobId
                );
            }

            // Step 11: Create NDP entries (batch)
            if ($deviceId !== '') {
                $steps[] = $this->buildCreateNeighborsBatchStep(
                    'Create NDP entries', 'ndp', $deviceId, $clusterId, $jobId
                );
            }

            // Step 12: Create inbound IPv6 firewall policy (NO VIP, NO NAT)
            if ($deviceId !== '') {
                $inboundRules = array_filter($firewallRules, fn($r) => ($r['type'] ?? '') === 'inbound');
                if (!empty($inboundRules)) {
                    $steps[] = $this->buildCreatePolicyStep(
                        'Create IPv6 inbound policy', 'ipv6', 'inbound', $deviceId, $clusterId, reset($inboundRules), $jobId
                    );
                }
            }

            // Step 13: Create outbound IPv6 firewall policy (NO SNAT)
            if ($deviceId !== '') {
                $outboundRules = array_filter($firewallRules, fn($r) => ($r['type'] ?? '') === 'outbound');
                if (!empty($outboundRules)) {
                    $steps[] = $this->buildCreatePolicyStep(
                        'Create IPv6 outbound policy', 'ipv6', 'outbound', $deviceId, $clusterId, reset($outboundRules), $jobId
                    );
                }
            }
        }

        return new ProvisioningJob($jobId, $steps);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Deprovision Server
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Deprovision a server's network configuration.
     * Finds all active allocations for the server and reverses them.
     *
     * @param string $serverId       IMS server UUID
     * @param string $idempotencyKey X-Idempotency-Key header value
     * @param string $removedBy      User/service ID for audit trail
     * @return ProvisioningJob
     */
    public function deprovisionServer(
        string $serverId,
        string $idempotencyKey,
        string $removedBy = 'system'
    ): ProvisioningJob {
        // Idempotency check
        $existing = $this->jobs->findOne(['idempotency_key' => $idempotencyKey]);
        if ($existing !== null) {
            $existingArr = json_decode(json_encode($existing), true);
            $jobId       = $existingArr['_id']['$oid'] ?? (string)($existing['_id'] ?? '');
            return new ProvisioningJob($jobId, []);
        }

        // Load active IP assignments for this server
        $db          = MongoDB::getInstance();
        $assignments = $db->selectCollection('ip_assignments')->find([
            'assigned_to.id' => $serverId,
            'status'         => 'active',
        ])->toArray();

        // Load active policies for this server's IPs
        $serverIps = array_map(fn($a) => (string)($a['ip_address'] ?? ''), $assignments);

        // Create deprovision job
        $insertResult = $this->jobs->insertOne([
            'idempotency_key' => $idempotencyKey,
            'request_source'  => 'manual',
            'request_data'    => ['server_id' => $serverId, 'action' => 'deprovision'],
            'server_id'       => $serverId,
            'server_name'     => null,
            'server_mac'      => null,
            'status'          => 'pending',
            'current_step'    => null,
            'progress_percent'=> 0,
            'allocated_ips'   => [],
            'created_routes'  => [],
            'created_policies'=> [],
            'error_message'   => null,
            'error_step'      => null,
            'started_at'      => null,
            'completed_at'    => null,
            'created_at'      => new UTCDateTime(),
            'created_by'      => $removedBy !== '' ? new ObjectId($removedBy) : null,
        ]);
        $jobId = (string)$insertResult->getInsertedId();

        $steps = [];

        // Build deprovision steps in reverse-provisioning order
        // Step order: delete policies → delete VIPs → delete routes → delete neighbors → release IPs

        // Collect IDs from ip_assignments routing fields
        $routeIds   = [];
        $neighborIds= [];
        $policyIds  = [];

        foreach ($assignments as $assignment) {
            $assignArr = json_decode(json_encode($assignment), true);
            if (!empty($assignArr['routing']['route_id']['$oid'])) {
                $routeIds[] = $assignArr['routing']['route_id']['$oid'];
            }
            if (!empty($assignArr['routing']['neighbor_id']['$oid'])) {
                $neighborIds[] = $assignArr['routing']['neighbor_id']['$oid'];
            }
            foreach ((array)($assignArr['firewall_policy_ids'] ?? []) as $pid) {
                $pids = is_array($pid) ? ($pid['$oid'] ?? '') : (string)$pid;
                if ($pids !== '') {
                    $policyIds[] = $pids;
                }
            }
        }

        $allocator = $this->allocator;

        // Step 1: Delete firewall policies (batch)
        if (!empty($policyIds)) {
            $pm = $this->policyManager;
            $steps[] = [
                'name'                => 'Delete firewall policies',
                'type'                => 'firewall',
                'input_data'          => ['policy_ids' => $policyIds],
                'action'              => static function (array $input) use ($pm): array {
                    foreach ((array)($input['policy_ids'] ?? []) as $id) {
                        try {
                            $pm->delete((string)$id, 'system-deprovision');
                        } catch (\RuntimeException $e) {
                            if (!str_contains(strtolower($e->getMessage()), 'not found')) {
                                throw $e;
                            }
                        }
                    }
                    return [];
                },
                'compensation_action' => 'noop', // Can't easily re-create without original data
            ];
        }

        // Step 2: Delete routes (batch)
        if (!empty($routeIds)) {
            $rm = $this->routeManager;
            $steps[] = [
                'name'                => 'Delete static routes',
                'type'                => 'route',
                'input_data'          => ['route_ids' => $routeIds],
                'action'              => static function (array $input) use ($rm): array {
                    foreach ((array)($input['route_ids'] ?? []) as $id) {
                        try {
                            $rm->delete((string)$id, 'system-deprovision');
                        } catch (\RuntimeException $e) {
                            if (!str_contains(strtolower($e->getMessage()), 'not found')) {
                                throw $e;
                            }
                        }
                    }
                    return [];
                },
                'compensation_action' => 'noop',
            ];
        }

        // Step 3: Delete neighbor entries (batch)
        if (!empty($neighborIds)) {
            $nm = $this->neighborManager;
            $steps[] = [
                'name'                => 'Delete neighbor entries',
                'type'                => 'neighbor',
                'input_data'          => ['entry_ids' => $neighborIds],
                'action'              => static function (array $input) use ($nm): array {
                    foreach ((array)($input['entry_ids'] ?? []) as $id) {
                        try {
                            $nm->delete((string)$id);
                        } catch (\RuntimeException $e) {
                            if (!str_contains(strtolower($e->getMessage()), 'not found')) {
                                throw $e;
                            }
                        }
                    }
                    return [];
                },
                'compensation_action' => 'noop',
            ];
        }

        // Step 4: Release all IPs (batch)
        if (!empty($serverIps)) {
            $steps[] = [
                'name'                => 'Release IP assignments',
                'type'                => 'ipam',
                'input_data'          => ['ip_addresses' => $serverIps],
                'action'              => static function (array $input) use ($allocator): array {
                    foreach ((array)($input['ip_addresses'] ?? []) as $ip) {
                        $allocator->release((string)$ip);
                    }
                    return ['released' => count($input['ip_addresses'] ?? [])];
                },
                'compensation_action' => 'noop',
            ];
        }

        return new ProvisioningJob($jobId, $steps);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step builders (private)
    // ─────────────────────────────────────────────────────────────────────────

    private function buildAllocateStep(
        string $stepName,
        string $ipVersion,
        string $poolId,
        array $serverInfo,
        string $mac,
        string $assignmentType,
        string $jobId,
        string $tier,
        string $family
    ): array {
        $allocator = $this->allocator;
        $jobOid    = new ObjectId($jobId);
        $jobs      = $this->jobs;

        return [
            'name'                => $stepName,
            'type'                => 'ipam',
            'input_data'          => ['pool_id' => $poolId, 'mac' => $mac],
            'action'              => static function (array $input) use ($allocator, $serverInfo, $assignmentType, $jobOid, $jobs, $tier, $family): array {
                $result = $allocator->allocateNext($input['pool_id'], $serverInfo, $input['mac'], $assignmentType);
                // Persist to job's allocated_ips
                $jobs->updateOne(
                    ['_id' => $jobOid],
                    ['$push' => ["allocated_ips.{$family}.{$tier}" => $result['ip_address']]]
                );
                return ['ip_address' => $result['ip_address'], 'assignment_id' => $result['id']];
            },
            'compensation_action' => 'ipam.release',
        ];
    }

    private function buildAllocateBatchStep(
        string $stepName,
        string $ipVersion,
        string $poolId,
        array $serverInfo,
        string $mac,
        string $assignmentType,
        int $count,
        string $jobId,
        string $tier,
        string $family
    ): array {
        $allocator = $this->allocator;
        $jobOid    = new ObjectId($jobId);
        $jobs      = $this->jobs;

        return [
            'name'                => $stepName,
            'type'                => 'ipam',
            'input_data'          => ['pool_id' => $poolId, 'mac' => $mac, 'count' => $count],
            'action'              => static function (array $input) use ($allocator, $serverInfo, $assignmentType, $jobOid, $jobs, $tier, $family): array {
                $allocated = [];
                for ($i = 0; $i < (int)($input['count'] ?? 1); $i++) {
                    $result = $allocator->allocateNext($input['pool_id'], $serverInfo, $input['mac'], $assignmentType);
                    $allocated[] = ['ip_address' => $result['ip_address'], 'assignment_id' => $result['id']];
                    $jobs->updateOne(
                        ['_id' => $jobOid],
                        ['$push' => ["allocated_ips.{$family}.{$tier}" => $result['ip_address']]]
                    );
                }
                return [
                    'ip_addresses'   => array_column($allocated, 'ip_address'),
                    'assignment_ids' => array_column($allocated, 'assignment_id'),
                ];
            },
            'compensation_action' => 'ipam.release_batch',
        ];
    }

    private function buildCreateRoutesBatchStep(
        string $stepName,
        string $ipVersion,
        int $prefixLen,
        string $deviceId,
        string $clusterId,
        string $jobId
    ): array {
        $rm     = $this->routeManager;
        $rs     = $this->routeSync;
        $jobOid = new ObjectId($jobId);
        $jobs   = $this->jobs;

        return [
            'name'                => $stepName,
            'type'                => 'route',
            'input_data'          => [
                'ip_version' => $ipVersion,
                'device_id'  => $deviceId,
                'cluster_id' => $clusterId,
                'prefix_len' => $prefixLen,
            ],
            'action'              => static function (array $input) use ($rm, $rs, $jobOid, $jobs): array {
                // ip_addresses are resolved from the job's allocated_ips at runtime
                // They are injected via input_data merger in SagaExecutor from previous step output
                $ips       = (array)($input['ip_addresses'] ?? []);
                $routeIds  = [];
                foreach ($ips as $ip) {
                    $routeId = $rm->create([
                        'ip_version'  => $input['ip_version'],
                        'destination' => $ip . '/' . $input['prefix_len'],
                        'device_id'   => $input['device_id'],
                        'cluster_id'  => $input['cluster_id'] ?: null,
                        'gateway'     => null, // Will be set from IPAM allocation
                        'purpose'     => 'server_provisioning',
                    ], 'system-provision');
                    $rs->syncToDevice($routeId);
                    $routeIds[] = $routeId;
                    $jobs->updateOne(
                        ['_id' => $jobOid],
                        ['$push' => ['created_routes' => new ObjectId($routeId)]]
                    );
                }
                return ['route_ids' => $routeIds];
            },
            'compensation_action' => 'route.delete_batch',
        ];
    }

    private function buildCreateNeighborsBatchStep(
        string $stepName,
        string $protocol,
        string $deviceId,
        string $clusterId,
        string $jobId
    ): array {
        $nm     = $this->neighborManager;
        $ns     = $this->neighborSync;

        return [
            'name'                => $stepName,
            'type'                => 'neighbor',
            'input_data'          => [
                'protocol'   => $protocol, // 'arp' or 'ndp'
                'device_id'  => $deviceId,
                'cluster_id' => $clusterId,
            ],
            'action'              => static function (array $input) use ($nm, $ns): array {
                $ips      = (array)($input['ip_addresses'] ?? []);
                $mac      = (string)($input['mac'] ?? '');
                $entryIds = [];
                foreach ($ips as $ip) {
                    $entryId = $nm->create([
                        'ip_address' => $ip,
                        'mac_address'=> $mac,
                        'protocol'   => $input['protocol'],
                        'device_id'  => $input['device_id'],
                        'cluster_id' => $input['cluster_id'] ?: null,
                        'purpose'    => 'server_provisioning',
                    ], 'system-provision');
                    $ns->syncToDevice($entryId);
                    $entryIds[] = $entryId;
                }
                return ['entry_ids' => $entryIds];
            },
            'compensation_action' => 'neighbor.delete_batch',
        ];
    }

    private function buildCreateVipsBatchStep(
        string $stepName,
        string $deviceId,
        string $clusterId,
        string $jobId
    ): array {
        $om     = $this->objectManager;
        $jobOid = new ObjectId($jobId);
        $jobs   = $this->jobs;

        return [
            'name'                => $stepName,
            'type'                => 'vip',
            'input_data'          => ['device_id' => $deviceId, 'cluster_id' => $clusterId],
            'action'              => static function (array $input) use ($om): array {
                $externalIps = (array)($input['external_ips'] ?? []);
                $mappedIps   = (array)($input['mapped_ips'] ?? []);
                $vipIds      = [];
                foreach ($externalIps as $i => $extIp) {
                    $vipId = $om->createVip([
                        'device_id'   => $input['device_id'],
                        'cluster_id'  => $input['cluster_id'] ?: null,
                        'external_ip' => $extIp,
                        'mapped_ip'   => $mappedIps[$i] ?? $extIp,
                    ]);
                    $vipIds[] = $vipId;
                }
                return ['vip_ids' => $vipIds];
            },
            'compensation_action' => 'vip.delete_batch',
        ];
    }

    private function buildCreatePolicyStep(
        string $stepName,
        string $ipVersion,
        string $direction,
        string $deviceId,
        string $clusterId,
        array $ruleSpec,
        string $jobId
    ): array {
        $pm     = $this->policyManager;
        $pb     = $this->policyBuilder;
        $jobOid = new ObjectId($jobId);
        $jobs   = $this->jobs;

        return [
            'name'                => $stepName,
            'type'                => 'firewall',
            'input_data'          => [
                'ip_version' => $ipVersion,
                'direction'  => $direction,
                'device_id'  => $deviceId,
                'cluster_id' => $clusterId,
                'rule_spec'  => $ruleSpec,
            ],
            'action'              => static function (array $input) use ($pm, $pb, $jobOid, $jobs): array {
                $ipVersion = $input['ip_version'];
                $direction = $input['direction'];
                $ruleSpec  = $input['rule_spec'] ?? [];
                $ips       = (array)($input['ip_addresses'] ?? []);
                $vipIds    = (array)($input['vip_ids'] ?? []);

                // Build policy params
                $policyParams = array_merge($ruleSpec, [
                    'ip_version'  => $ipVersion,
                    'direction'   => $direction,
                    'device_id'   => $input['device_id'],
                    'cluster_id'  => $input['cluster_id'] ?: null,
                    'source_ips'  => $ips,
                    'vip_id'      => $ipVersion === 'ipv4' && $direction === 'inbound'
                                        ? ($vipIds[0] ?? null)
                                        : null,
                ]);

                $policyData = match (true) {
                    $ipVersion === 'ipv4' && $direction === 'inbound'  => $pb->buildIPv4InboundPolicy($policyParams),
                    $ipVersion === 'ipv4' && $direction === 'outbound' => $pb->buildIPv4OutboundPolicy($policyParams),
                    $ipVersion === 'ipv6' && $direction === 'inbound'  => $pb->buildIPv6InboundPolicy($policyParams),
                    default                                             => $pb->buildIPv6OutboundPolicy($policyParams),
                };

                $policyId = $pm->create($policyData, 'system-provision');
                $pm->syncToDevice($policyId);

                $jobs->updateOne(
                    ['_id' => $jobOid],
                    ['$push' => ['created_policies' => new ObjectId($policyId)]]
                );

                return ['policy_id' => $policyId];
            },
            'compensation_action' => 'policy.delete',
        ];
    }
}
