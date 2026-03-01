<?php

declare(strict_types=1);

namespace NMS\Core\Models\Ipam;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\IPUtils;

/**
 * IPAllocator
 *
 * Atomic IP allocation using MongoDB findOneAndUpdate + unique index on ip_address.
 * Race-condition safe: two concurrent requests cannot claim the same IP.
 *
 * Allocation strategy:
 *   1. findOneAndUpdate {status: "released"} — recycle freed IPs first (atomic)
 *   2. If none available: find next IP in pool range not in collection, insertOne
 *   3. Unique index on ip_address rejects any concurrent duplicate insert
 *   4. Retry up to MAX_CONTENTION_RETRIES on duplicate key (race condition)
 */
class IPAllocator
{
    private \MongoDB\Collection $assignments;
    private \MongoDB\Collection $history;
    private PoolManager $poolManager;

    private const MAX_CONTENTION_RETRIES = 3;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->assignments = $db->selectCollection('ip_assignments');
        $this->history     = $db->selectCollection('ip_assignment_history');
        $this->poolManager = new PoolManager();
    }

    /**
     * Allocate the next available IP from a pool (atomic, race-safe).
     *
     * @param string $poolId        Target pool ID
     * @param array  $assignedTo    { type, id, name, site_id }
     * @param string $mac           MAC address of the requester
     * @param string $assignmentType 'layer2' | 'layer3'
     * @return array                The created ip_assignment document
     * @throws \RuntimeException    If pool is full, missing, or contention exceeded
     */
    public function allocateNext(
        string $poolId,
        array $assignedTo,
        string $mac = '',
        string $assignmentType = 'layer2'
    ): array {
        $pool = $this->poolManager->findPoolById($poolId);
        if (!$pool) {
            throw new \RuntimeException("Pool {$poolId} not found");
        }
        if (($pool['status'] ?? '') === 'full') {
            throw new \RuntimeException("Pool {$poolId} is full");
        }

        $poolOid   = new ObjectId($poolId);
        $ipVersion = (string) ($pool['ip_version'] ?? 'ipv4');

        $assignFields = $this->buildAssignFields($poolOid, $ipVersion, $assignedTo, $mac, $assignmentType);

        for ($attempt = 0; $attempt < self::MAX_CONTENTION_RETRIES; $attempt++) {
            // Step 1: Atomically recycle a released IP
            $claimed = $this->assignments->findOneAndUpdate(
                ['pool_id' => $poolOid, 'status' => 'released'],
                ['$set' => array_merge($assignFields, [
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ])],
                [
                    'sort'           => ['ip_address' => 1],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ]
            );

            if ($claimed !== null) {
                $result = $this->docToArray($claimed);
                $this->poolManager->incrementUsed($poolId);
                if ($ipVersion === 'ipv6') {
                    $this->poolManager->incrementAllocatedPrefixes($poolId);
                }
                $this->writeHistory($result['ip_address'], 'assigned', null, $result, $assignedTo);
                return $result;
            }

            // Step 2: Find next free IP (not in collection)
            $nextIp = $this->findNextFreeIp($pool);
            if ($nextIp === null) {
                throw new \RuntimeException("Pool {$poolId} is exhausted");
            }

            // Step 3: Insert atomically — unique index rejects concurrent duplicates
            try {
                $insertDoc = array_merge($assignFields, [
                    'ip_address' => $nextIp,
                    'created_at' => new \MongoDB\BSON\UTCDateTime(),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ]);
                $insertResult = $this->assignments->insertOne($insertDoc);
                $created      = $this->docToArray(
                    $this->assignments->findOne(['_id' => $insertResult->getInsertedId()])
                );
                $this->poolManager->incrementUsed($poolId);
                if ($ipVersion === 'ipv6') {
                    $this->poolManager->incrementAllocatedPrefixes($poolId);
                }
                $this->writeHistory($nextIp, 'assigned', null, $created, $assignedTo);
                return $created;
            } catch (BulkWriteException $e) {
                // Duplicate key — another request claimed this IP, retry
                if ($attempt < self::MAX_CONTENTION_RETRIES - 1) {
                    continue;
                }
                throw new \RuntimeException(
                    "IP allocation contention: exceeded max retries for pool {$poolId}"
                );
            }
        }

        throw new \RuntimeException("IP allocation failed after retries for pool {$poolId}");
    }

    /**
     * Assign a specific IP address from a pool.
     *
     * @param string $ip            The IP to assign (must be within pool network)
     * @param string $poolId        Target pool ID
     * @param array  $assignedTo    { type, id, name, site_id }
     * @param string $assignmentType 'layer2' | 'layer3'
     * @param string $mac
     * @return array                The created ip_assignment document
     */
    public function assignSpecific(
        string $ip,
        string $poolId,
        array $assignedTo,
        string $assignmentType = 'layer2',
        string $mac = ''
    ): array {
        $pool = $this->poolManager->findPoolById($poolId);
        if (!$pool) {
            throw new \RuntimeException("Pool {$poolId} not found");
        }

        if (!IPUtils::networkContains($pool['network'], $ip)) {
            throw new \InvalidArgumentException("IP {$ip} is not within pool network {$pool['network']}");
        }

        // Reject if already active/reserved/quarantined
        $existing = $this->assignments->findOne([
            'ip_address' => $ip,
            'status'     => ['$in' => ['active', 'reserved', 'quarantine']],
        ]);
        if ($existing !== null) {
            throw new \RuntimeException("IP {$ip} is already in use (status: {$existing['status']})");
        }

        $poolOid   = new ObjectId($poolId);
        $ipVersion = (string) ($pool['ip_version'] ?? 'ipv4');

        $doc = array_merge($this->buildAssignFields($poolOid, $ipVersion, $assignedTo, $mac, $assignmentType), [
            'ip_address' => $ip,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ]);

        try {
            $insertResult = $this->assignments->insertOne($doc);
        } catch (BulkWriteException $e) {
            throw new \RuntimeException("IP {$ip} was claimed by a concurrent request");
        }

        $created = $this->docToArray(
            $this->assignments->findOne(['_id' => $insertResult->getInsertedId()])
        );
        $this->poolManager->incrementUsed($poolId);
        if ($ipVersion === 'ipv6') {
            $this->poolManager->incrementAllocatedPrefixes($poolId);
        }
        $this->writeHistory($ip, 'assigned', null, $created, $assignedTo);
        return $created;
    }

    /**
     * Release an IP: set status to 'released', clear routing/assignment fields.
     * Decrements pool used_addresses counter.
     */
    public function release(string $ip): bool
    {
        $existing = $this->assignments->findOne(['ip_address' => $ip, 'status' => 'active']);
        if ($existing === null) {
            return false;
        }

        $previousState = $this->docToArray($existing);
        $poolId        = (string) ($existing['pool_id'] ?? '');
        $ipVersion     = (string) ($existing['ip_version'] ?? 'ipv4');

        $this->assignments->updateOne(
            ['ip_address' => $ip, 'status' => 'active'],
            [
                '$set' => [
                    'status'               => 'released',
                    'assigned_to'          => null,
                    'mac_address'          => null,
                    'hostname'             => null,
                    'reverse_dns'          => null,
                    'firewall_policy_ids'  => [],
                    'routing'              => [
                        'gateway_ip'           => null,
                        'static_route_added'   => false,
                        'route_id'             => null,
                        'neighbor_entry_added' => false,
                        'neighbor_id'          => null,
                    ],
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
            ]
        );

        if ($poolId) {
            $this->poolManager->decrementUsed($poolId);
            if ($ipVersion === 'ipv6') {
                $this->poolManager->decrementAllocatedPrefixes($poolId);
            }
        }

        $newState = $this->docToArray($this->assignments->findOne(['ip_address' => $ip]));
        $this->writeHistory($ip, 'released', $previousState, $newState, []);
        return true;
    }

    /**
     * Mark an IP assignment as L3 by storing routing metadata.
     * Called after static route + ARP/NDP entry have been pushed to the device.
     */
    public function markL3(string $ip, array $routingInfo): void
    {
        $this->assignments->updateOne(
            ['ip_address' => $ip, 'status' => 'active'],
            [
                '$set' => [
                    'routing.gateway_ip'           => $routingInfo['gateway_ip'] ?? null,
                    'routing.static_route_added'   => (bool) ($routingInfo['static_route_added'] ?? false),
                    'routing.route_id'             => isset($routingInfo['route_id'])
                                                        ? new ObjectId($routingInfo['route_id']) : null,
                    'routing.neighbor_entry_added' => (bool) ($routingInfo['neighbor_entry_added'] ?? false),
                    'routing.neighbor_id'          => isset($routingInfo['neighbor_id'])
                                                        ? new ObjectId($routingInfo['neighbor_id']) : null,
                    'updated_at'                   => new \MongoDB\BSON\UTCDateTime(),
                ],
            ]
        );
    }

    /**
     * Find an assignment by IP address (any status).
     */
    public function findByIp(string $ip): ?array
    {
        $doc = $this->assignments->findOne(['ip_address' => $ip]);
        return $doc ? $this->docToArray($doc) : null;
    }

    /**
     * List assignments with optional filters.
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];
        if (!empty($filters['pool_id'])) {
            $filter['pool_id'] = new ObjectId($filters['pool_id']);
        }
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['assigned_to_id'])) {
            $filter['assigned_to.id'] = $filters['assigned_to_id'];
        }
        if (!empty($filters['search'])) {
            $filter['$or'] = [
                ['ip_address' => ['$regex' => $filters['search'], '$options' => 'i']],
                ['hostname'   => ['$regex' => $filters['search'], '$options' => 'i']],
            ];
        }

        $total  = $this->assignments->countDocuments($filter);
        $cursor = $this->assignments->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['ip_address' => 1],
        ]);

        return [
            'data'      => array_map([$this, 'docToArray'], $cursor->toArray()),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Update mutable metadata fields on an assignment.
     */
    public function update(string $ip, array $data): bool
    {
        $set = [];
        foreach (['hostname', 'description', 'notes', 'tags', 'reverse_dns'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        if (array_key_exists('status', $data)) {
            $allowed = ['active', 'reserved', 'quarantine', 'released'];
            if (!in_array($data['status'], $allowed, true)) {
                throw new \InvalidArgumentException("Invalid status: {$data['status']}");
            }
            $set['status'] = $data['status'];
        }
        if (empty($set)) {
            return false;
        }
        $set['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        $result = $this->assignments->updateOne(['ip_address' => $ip], ['$set' => $set]);
        return $result->getModifiedCount() > 0;
    }

    /**
     * Retrieve assignment history for an IP, most recent first.
     */
    public function getHistory(string $ip, int $page = 1, int $perPage = 50): array
    {
        $filter = ['ip_address' => $ip];
        $total  = $this->history->countDocuments($filter);
        $cursor = $this->history->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['performed_at' => -1],
        ]);

        return [
            'data'      => array_map([$this, 'docToArray'], $cursor->toArray()),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build the common assignment fields (without ip_address + created_at/updated_at).
     */
    private function buildAssignFields(
        ObjectId $poolOid,
        string $ipVersion,
        array $assignedTo,
        string $mac,
        string $assignmentType
    ): array {
        return [
            'pool_id'             => $poolOid,
            'subnet_id'           => null,
            'ip_version'          => $ipVersion,
            'assignment_type'     => $assignmentType,
            'mac_address'         => $mac,
            'assigned_to'         => [
                'type'    => $assignedTo['type'] ?? 'server',
                'id'      => $assignedTo['id'] ?? null,
                'name'    => $assignedTo['name'] ?? null,
                'site_id' => isset($assignedTo['site_id'])
                                ? new ObjectId($assignedTo['site_id']) : null,
            ],
            'routing'             => [
                'gateway_ip'           => null,
                'static_route_added'   => false,
                'route_id'             => null,
                'neighbor_entry_added' => false,
                'neighbor_id'          => null,
            ],
            'firewall_policy_ids' => [],
            'hostname'            => null,
            'reverse_dns'         => null,
            'status'              => 'active',
            'lease_expires'       => null,
            'description'         => null,
            'tags'                => [],
            'notes'               => null,
            'created_by'          => null,
            'last_modified_by'    => null,
        ];
    }

    /**
     * Walk the pool's IP range and find the first IP not present in ip_assignments.
     * Returns null if the pool is exhausted.
     */
    private function findNextFreeIp(array $pool): ?string
    {
        $poolOid = new ObjectId($pool['id']);
        $network = (string) $pool['network'];
        $first   = (string) $pool['first_usable_ip'];
        $last    = (string) $pool['last_usable_ip'];

        // Fetch all non-released IPs in sorted order (only the ip_address field)
        $cursor  = $this->assignments->find(
            ['pool_id' => $poolOid, 'status' => ['$ne' => 'released']],
            ['sort' => ['ip_address' => 1], 'projection' => ['ip_address' => 1, '_id' => 0]]
        );
        $usedSet = [];
        foreach ($cursor as $doc) {
            $usedSet[(string) ($doc['ip_address'] ?? '')] = true;
        }

        $current = $first;
        while ($current !== null) {
            if (!isset($usedSet[$current])) {
                return $current;
            }
            // Advance to next IP; stop if past last usable
            $next = IPUtils::nextAvailable($current, $network);
            if ($next === null || $this->ipCompare($next, $last) > 0) {
                return null;
            }
            $current = $next;
        }
        return null;
    }

    private function ipCompare(string $a, string $b): int
    {
        if (IPUtils::isIPv4($a)) {
            return ip2long($a) <=> ip2long($b);
        }
        return strcmp(inet_pton($a), inet_pton($b));
    }

    private function writeHistory(
        string $ip,
        string $action,
        ?array $previousState,
        ?array $newState,
        array $assignedTo
    ): void {
        try {
            $this->history->insertOne([
                'ip_address'     => $ip,
                'action'         => $action,
                'previous_state' => $previousState,
                'new_state'      => $newState,
                'assigned_to'    => $assignedTo ?: null,
                'reason'         => null,
                'performed_by'   => null,
                'performed_at'   => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Throwable) {
            // Non-critical — do not fail the allocation if history write fails
        }
    }

    private function docToArray(mixed $doc): array
    {
        if ($doc === null) {
            return [];
        }
        $arr = json_decode(json_encode($doc), true);
        if (isset($arr['_id']['$oid'])) {
            $arr['id'] = $arr['_id']['$oid'];
            unset($arr['_id']);
        } elseif (isset($arr['_id'])) {
            $arr['id'] = (string) $arr['_id'];
            unset($arr['_id']);
        }
        return $arr;
    }
}
