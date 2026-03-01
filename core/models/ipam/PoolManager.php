<?php

declare(strict_types=1);

namespace NMS\Core\Models\Ipam;

use MongoDB\BSON\ObjectId;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\IPUtils;

/**
 * PoolManager
 *
 * CRUD for ip_blocks, ip_pools, ip_subnets, and ip_reservations collections.
 * Keeps used_addresses + utilization_percent accurate after every assign/release.
 * ip_version is required on all records.
 */
class PoolManager
{
    private \MongoDB\Collection $blocks;
    private \MongoDB\Collection $pools;
    private \MongoDB\Collection $subnets;
    private \MongoDB\Collection $reservations;
    private SubnetCalculator $calc;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->blocks       = $db->selectCollection('ip_blocks');
        $this->pools        = $db->selectCollection('ip_pools');
        $this->subnets      = $db->selectCollection('ip_subnets');
        $this->reservations = $db->selectCollection('ip_reservations');
        $this->calc         = new SubnetCalculator();
    }

    // ─── IP Blocks ────────────────────────────────────────────────────────────

    public function listBlocks(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $filter['$or'] = [
                ['network'     => ['$regex' => $filters['search'], '$options' => 'i']],
                ['description' => ['$regex' => $filters['search'], '$options' => 'i']],
            ];
        }

        $total  = $this->blocks->countDocuments($filter);
        $cursor = $this->blocks->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['network' => 1],
        ]);

        return [
            'data'      => $this->cursorToArray($cursor),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function createBlock(array $data): string
    {
        $cidr    = $data['network'];
        $parsed  = IPUtils::parseCIDR($cidr);
        $version = $parsed['version'] === 4 ? 'ipv4' : 'ipv6';
        $total   = $this->calc->getTotalAddresses($cidr);

        $doc = [
            'network'         => $cidr,
            'ip_version'      => $data['ip_version'] ?? $version,
            'prefix_length'   => $parsed['prefix'],
            'total_addresses' => is_string($total) ? $total : (int) $total,
            'source'          => $data['source'] ?? null,
            'rir_handle'      => $data['rir_handle'] ?? null,
            'whois_info'      => $data['whois_info'] ?? null,
            'description'     => $data['description'] ?? null,
            'status'          => $data['status'] ?? 'active',
            'created_at'      => new \MongoDB\BSON\UTCDateTime(),
        ];

        $result = $this->blocks->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findBlockById(string $id): ?array
    {
        $doc = $this->blocks->findOne(['_id' => new ObjectId($id)]);
        return $doc ? $this->docToArray($doc) : null;
    }

    // ─── IP Pools ─────────────────────────────────────────────────────────────

    public function listPools(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['site_id'])) {
            $filter['site_id'] = new ObjectId($filters['site_id']);
        }
        if (!empty($filters['block_id'])) {
            $filter['block_id'] = new ObjectId($filters['block_id']);
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['pool_type'])) {
            $filter['pool_type'] = $filters['pool_type'];
        }

        $total  = $this->pools->countDocuments($filter);
        $cursor = $this->pools->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['network' => 1],
        ]);

        return [
            'data'      => $this->cursorToArray($cursor),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function createPool(array $data): string
    {
        $cidr    = $data['network'];
        $parsed  = IPUtils::parseCIDR($cidr);
        $version = $parsed['version'] === 4 ? 'ipv4' : 'ipv6';
        $total   = $this->calc->getTotalAddresses($cidr);
        $first   = $this->calc->getFirstUsable($cidr);
        $last    = $this->calc->getLastUsable($cidr);
        $isIPv6  = $version === 'ipv6';

        $doc = [
            'block_id'            => isset($data['block_id']) ? new ObjectId($data['block_id']) : null,
            'name'                => $data['name'],
            'network'             => $cidr,
            'ip_version'          => $data['ip_version'] ?? $version,
            'prefix_length'       => $parsed['prefix'],
            'gateway_ip'          => $data['gateway_ip'] ?? null,
            'first_usable_ip'     => $first,
            'last_usable_ip'      => $last,
            'total_addresses'     => is_string($total) ? $total : (int) $total,
            'used_addresses'      => 0,
            'reserved_addresses'  => 0,
            'utilization_percent' => 0.0,
            'pool_type'           => $data['pool_type'] ?? 'public',
            'allocation_type'     => $data['allocation_type'] ?? 'layer3',
            'vlan_id'             => $data['vlan_id'] ?? null,
            'site_id'             => isset($data['site_id']) ? new ObjectId($data['site_id']) : null,
            'datacenter'          => $data['datacenter'] ?? null,
            'router_device_id'    => isset($data['router_device_id'])
                                        ? new ObjectId($data['router_device_id']) : null,
            'router_cluster_id'   => isset($data['router_cluster_id'])
                                        ? new ObjectId($data['router_cluster_id']) : null,
            'interface_name'      => $data['interface_name'] ?? null,
            'description'         => $data['description'] ?? null,
            'auto_assign'         => $data['auto_assign'] ?? true,
            'status'              => $data['status'] ?? 'active',
            'created_at'          => new \MongoDB\BSON\UTCDateTime(),
            'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
        ];

        // IPv6: track at /64 allocation level
        if ($isIPv6) {
            $doc['allocated_prefixes'] = 0;
            $total64 = $this->calc->countIPv6Prefixes($cidr, 64);
            $doc['total_prefixes'] = is_string($total64) ? $total64 : (int) $total64;
        }

        $result = $this->pools->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findPoolById(string $id): ?array
    {
        $doc = $this->pools->findOne(['_id' => new ObjectId($id)]);
        return $doc ? $this->docToArray($doc) : null;
    }

    public function updatePool(string $id, array $data): bool
    {
        $set     = [];
        $allowed = [
            'name', 'gateway_ip', 'description', 'status', 'auto_assign',
            'pool_type', 'allocation_type', 'vlan_id', 'interface_name', 'datacenter',
        ];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        foreach (['site_id', 'router_device_id', 'router_cluster_id'] as $ref) {
            if (isset($data[$ref])) {
                $set[$ref] = new ObjectId($data[$ref]);
            }
        }

        if (empty($set)) {
            return false;
        }
        $set['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        $result = $this->pools->updateOne(['_id' => new ObjectId($id)], ['$set' => $set]);
        return $result->getModifiedCount() > 0;
    }

    public function deletePool(string $id): bool
    {
        $db          = MongoDB::getInstance();
        $activeCount = $db->selectCollection('ip_assignments')->countDocuments([
            'pool_id' => new ObjectId($id),
            'status'  => ['$in' => ['active', 'reserved', 'quarantine']],
        ]);
        if ($activeCount > 0) {
            throw new \RuntimeException(
                "Cannot delete pool with {$activeCount} active assignment(s)"
            );
        }

        $result = $this->pools->deleteOne(['_id' => new ObjectId($id)]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * Increment used_addresses by 1 and recalculate utilization_percent.
     * Called by IPAllocator after each successful allocation.
     */
    public function incrementUsed(string $poolId): void
    {
        $this->pools->updateOne(
            ['_id' => new ObjectId($poolId)],
            [
                '$inc' => ['used_addresses' => 1],
                '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
            ]
        );
        $this->refreshUtilization($poolId);
    }

    /**
     * Decrement used_addresses by 1 and recalculate utilization_percent.
     * Called by IPAllocator after each successful release.
     */
    public function decrementUsed(string $poolId): void
    {
        $this->pools->updateOne(
            ['_id' => new ObjectId($poolId)],
            [
                '$inc' => ['used_addresses' => -1],
                '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
            ]
        );
        $this->refreshUtilization($poolId);
    }

    /**
     * Increment allocated_prefixes (IPv6 /64 tracking).
     */
    public function incrementAllocatedPrefixes(string $poolId): void
    {
        $this->pools->updateOne(
            ['_id' => new ObjectId($poolId)],
            ['$inc' => ['allocated_prefixes' => 1]]
        );
    }

    /**
     * Decrement allocated_prefixes (IPv6 /64 tracking).
     */
    public function decrementAllocatedPrefixes(string $poolId): void
    {
        $this->pools->updateOne(
            ['_id' => new ObjectId($poolId)],
            ['$inc' => ['allocated_prefixes' => -1]]
        );
    }

    /**
     * Return number of free addresses in a pool (total - used - reserved).
     */
    public function getAvailableCount(string $poolId): int
    {
        $pool = $this->findPoolById($poolId);
        if (!$pool) {
            return 0;
        }
        $total    = (int) ($pool['total_addresses'] ?? 0);
        $used     = (int) ($pool['used_addresses'] ?? 0);
        $reserved = (int) ($pool['reserved_addresses'] ?? 0);
        return max(0, $total - $used - $reserved);
    }

    /**
     * Return utilization stats broken down by status from ip_assignments.
     */
    public function getUsageStats(string $poolId): array
    {
        $pool = $this->findPoolById($poolId);
        if (!$pool) {
            return [];
        }

        $db          = MongoDB::getInstance();
        $statusCounts = $db->selectCollection('ip_assignments')->aggregate([
            ['$match' => ['pool_id' => new ObjectId($poolId)]],
            ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
        ])->toArray();

        $counts = [];
        foreach ($statusCounts as $row) {
            $counts[(string)($row['_id'] ?? 'unknown')] = (int) ($row['count'] ?? 0);
        }

        return [
            'pool_id'             => $poolId,
            'network'             => $pool['network'],
            'ip_version'          => $pool['ip_version'],
            'total_addresses'     => $pool['total_addresses'],
            'used_addresses'      => $pool['used_addresses'] ?? 0,
            'reserved_addresses'  => $pool['reserved_addresses'] ?? 0,
            'released_addresses'  => $counts['released'] ?? 0,
            'active_addresses'    => $counts['active'] ?? 0,
            'quarantine_addresses'=> $counts['quarantine'] ?? 0,
            'utilization_percent' => $pool['utilization_percent'] ?? 0.0,
        ];
    }

    // ─── IP Subnets ───────────────────────────────────────────────────────────

    public function listSubnets(string $poolId): array
    {
        $cursor = $this->subnets->find(
            ['pool_id' => new ObjectId($poolId)],
            ['sort' => ['network' => 1]]
        );
        return $this->cursorToArray($cursor);
    }

    public function createSubnet(array $data): string
    {
        $cidr    = $data['network'];
        $parsed  = IPUtils::parseCIDR($cidr);
        $version = $parsed['version'] === 4 ? 'ipv4' : 'ipv6';
        $total   = $this->calc->getTotalAddresses($cidr);

        $doc = [
            'pool_id'          => new ObjectId($data['pool_id']),
            'parent_subnet_id' => isset($data['parent_subnet_id'])
                                    ? new ObjectId($data['parent_subnet_id']) : null,
            'network'          => $cidr,
            'ip_version'       => $data['ip_version'] ?? $version,
            'prefix_length'    => $parsed['prefix'],
            'gateway_ip'       => $data['gateway_ip'] ?? null,
            'first_usable_ip'  => $this->calc->getFirstUsable($cidr),
            'last_usable_ip'   => $this->calc->getLastUsable($cidr),
            'total_addresses'  => is_string($total) ? $total : (int) $total,
            'used_addresses'   => 0,
            'purpose'          => $data['purpose'] ?? null,
            'vlan_id'          => $data['vlan_id'] ?? null,
            'description'      => $data['description'] ?? null,
            'status'           => $data['status'] ?? 'active',
            'created_at'       => new \MongoDB\BSON\UTCDateTime(),
        ];

        $result = $this->subnets->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findSubnetById(string $id): ?array
    {
        $doc = $this->subnets->findOne(['_id' => new ObjectId($id)]);
        return $doc ? $this->docToArray($doc) : null;
    }

    // ─── IP Reservations ──────────────────────────────────────────────────────

    public function createReservation(array $data): string
    {
        $doc = [
            'ip_address'     => $data['ip_address'],
            'ip_version'     => $data['ip_version'],
            'pool_id'        => new ObjectId($data['pool_id']),
            'purpose'        => $data['purpose'] ?? null,
            'reserved_until' => isset($data['reserved_until'])
                                    ? new \MongoDB\BSON\UTCDateTime(
                                        (int) (strtotime($data['reserved_until']) * 1000)
                                      )
                                    : null,
            'reserved_by'    => isset($data['reserved_by'])
                                    ? new ObjectId($data['reserved_by']) : null,
            'created_at'     => new \MongoDB\BSON\UTCDateTime(),
        ];

        $this->pools->updateOne(
            ['_id' => new ObjectId($data['pool_id'])],
            ['$inc' => ['reserved_addresses' => 1]]
        );

        $result = $this->reservations->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function listReservations(string $poolId): array
    {
        $cursor = $this->reservations->find(['pool_id' => new ObjectId($poolId)]);
        return $this->cursorToArray($cursor);
    }

    public function deleteReservation(string $id): bool
    {
        $reservation = $this->reservations->findOne(['_id' => new ObjectId($id)]);
        if ($reservation !== null) {
            $poolId = (string) ($reservation['pool_id'] ?? '');
            $this->pools->updateOne(
                ['_id' => new ObjectId($poolId)],
                ['$inc' => ['reserved_addresses' => -1]]
            );
        }
        $result = $this->reservations->deleteOne(['_id' => new ObjectId($id)]);
        return $result->getDeletedCount() > 0;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function refreshUtilization(string $poolId): void
    {
        $pool = $this->findPoolById($poolId);
        if (!$pool) {
            return;
        }

        $used  = max(0, (int) ($pool['used_addresses'] ?? 0));
        $total = (int) ($pool['total_addresses'] ?? 1);
        $util  = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

        $set = ['utilization_percent' => $util];

        // Auto-flip status between 'active' and 'full'
        $current = $pool['status'] ?? 'active';
        if ($used >= $total && $current === 'active') {
            $set['status'] = 'full';
        } elseif ($used < $total && $current === 'full') {
            $set['status'] = 'active';
        }

        $this->pools->updateOne(['_id' => new ObjectId($poolId)], ['$set' => $set]);
    }

    private function cursorToArray(\MongoDB\Driver\Cursor $cursor): array
    {
        return array_map([$this, 'docToArray'], $cursor->toArray());
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
