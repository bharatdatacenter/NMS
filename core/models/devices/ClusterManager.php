<?php

declare(strict_types=1);

namespace NMS\Core\Models\Devices;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\Collection;

/**
 * ClusterManager
 *
 * CRUD for the `device_clusters` collection.
 * Clusters represent HA pairs or groups (FortiGate HA, VRRP, switch stacks).
 *
 * Each cluster has:
 *   - members[]   — array of {device_id, node_ip, role: primary|secondary|member}
 *   - management_ip — the cluster VIP/management IP used for config pushes
 *   - type         — "ha_pair" | "stack" | "vrrp"
 *   - vendor       — must match member devices
 *
 * Failover events are recorded in the `cluster_events` collection.
 */
class ClusterManager extends Collection
{
    private Collection $events;

    public function __construct()
    {
        parent::__construct('device_clusters');
        $this->events = new class extends Collection {
            public function __construct() { parent::__construct('cluster_events'); }
        };
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    /**
     * List clusters with optional filters.
     *
     * @param array $filters  Keys: vendor, type, site_id, status
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['vendor'])) {
            $filter['vendor'] = $filters['vendor'];
        }
        if (!empty($filters['type'])) {
            $filter['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }
        if (!empty($filters['site_id'])) {
            $filter['site_id'] = new ObjectId($filters['site_id']);
        }

        return $this->paginate($filter, $page, $perPage, ['sort' => ['name' => 1]]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a new cluster.
     *
     * Required: name, vendor, type, management_ip, members[]
     * Each member: {device_id, node_ip, role: primary|secondary|member}
     */
    public function create(array $data, string $createdBy): string
    {
        $members = array_map(function (array $m): array {
            return [
                'device_id' => new ObjectId($m['device_id']),
                'node_ip'   => $m['node_ip'],
                'role'      => $m['role'] ?? 'member',
                'status'    => 'unknown',
            ];
        }, $data['members'] ?? []);

        $doc = [
            'name'          => $data['name'],
            'vendor'        => $data['vendor'],
            'type'          => $data['type'] ?? 'ha_pair',
            'management_ip' => $data['management_ip'],
            'site_id'       => isset($data['site_id']) ? new ObjectId($data['site_id']) : null,
            'members'       => $members,
            'status'        => 'unknown',
            'failover_count'=> 0,
            'last_failover' => null,
            'notes'         => $data['notes'] ?? null,
            'tags'          => $data['tags'] ?? [],
            'created_by'    => new ObjectId($createdBy),
        ];

        $clusterId = $this->insertOne($doc);

        // Tag each member device with this cluster
        $this->tagMemberDevices($clusterId, $members, $data['vendor']);

        return $clusterId;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * Update cluster metadata (name, management_ip, notes, tags).
     * Does NOT update members — use addMember/removeMember for that.
     */
    public function update(string $id, array $data): bool
    {
        $set = [];
        foreach (['name', 'management_ip', 'notes', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        if (array_key_exists('tags', $data)) {
            $set['tags'] = $data['tags'];
        }
        if (empty($set)) {
            return false;
        }
        return $this->updateById($id, ['$set' => $set]) > 0;
    }

    // ─── Member Status Polling ─────────────────────────────────────────────────

    /**
     * Poll all member devices of a cluster and update their status.
     * Returns the cluster's current status summary.
     *
     * Used by StatusHandler — actual device polling happens in the handler
     * via DeviceFactory adapters. This method only updates stored status.
     *
     * @param  string $clusterId
     * @param  array  $memberStatuses  [{device_id, status, role, details}]
     */
    public function updateMemberStatuses(string $clusterId, array $memberStatuses): void
    {
        $cluster = $this->findById($clusterId);
        if (!$cluster) {
            return;
        }

        $members = $cluster['members'] ?? [];
        $statusMap = [];
        foreach ($memberStatuses as $ms) {
            $statusMap[(string)$ms['device_id']] = $ms;
        }

        // Update each member's status inline
        $updatedMembers = array_map(function (array $member) use ($statusMap): array {
            $deviceId = isset($member['device_id']['$oid'])
                ? $member['device_id']['$oid']
                : (string)($member['device_id'] ?? '');

            if (isset($statusMap[$deviceId])) {
                $member['status']  = $statusMap[$deviceId]['status'];
                $member['details'] = $statusMap[$deviceId]['details'] ?? null;
                $member['role']    = $statusMap[$deviceId]['role'] ?? $member['role'];
            }
            return $member;
        }, $members);

        // Derive cluster status from member statuses
        $memberStatuses_  = array_column($memberStatuses, 'status');
        $clusterStatus    = $this->deriveClusterStatus($memberStatuses_);

        $this->updateById($clusterId, [
            '$set' => [
                'members'    => $updatedMembers,
                'status'     => $clusterStatus,
                'last_polled'=> new UTCDateTime(),
            ],
        ]);
    }

    // ─── Failover Events ──────────────────────────────────────────────────────

    /**
     * Record a failover event and increment the cluster's failover counter.
     *
     * @param string $clusterId
     * @param array  $event  {type, from_device_id, to_device_id, reason, initiated_by}
     */
    public function recordFailover(string $clusterId, array $event): string
    {
        $doc = [
            'cluster_id'      => new ObjectId($clusterId),
            'type'            => $event['type'] ?? 'manual',
            'from_device_id'  => isset($event['from_device_id']) ? new ObjectId($event['from_device_id']) : null,
            'to_device_id'    => isset($event['to_device_id'])   ? new ObjectId($event['to_device_id'])   : null,
            'reason'          => $event['reason'] ?? null,
            'initiated_by'    => $event['initiated_by'] ?? 'system',
            'status'          => 'completed',
            'occurred_at'     => new UTCDateTime(),
        ];

        $eventId = $this->events->insertOne($doc);

        // Increment failover counter on cluster
        $this->updateById($clusterId, [
            '$set' => ['last_failover' => new UTCDateTime()],
            '$inc' => ['failover_count' => 1],
        ]);

        return $eventId;
    }

    // ─── Events ───────────────────────────────────────────────────────────────

    /**
     * Get events for a cluster, newest first.
     */
    public function getEvents(string $clusterId, int $limit = 50): array
    {
        $cursor = $this->events->getCollection()->find(
            ['cluster_id' => new ObjectId($clusterId)],
            ['sort' => ['occurred_at' => -1], 'limit' => $limit]
        );

        return array_map(
            fn($doc) => json_decode(json_encode($doc), true),
            $cursor->toArray()
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Tag member devices with cluster_id and cluster_role in their documents.
     */
    private function tagMemberDevices(string $clusterId, array $members, string $vendor): void
    {
        try {
            $deviceCollection = \NMS\Core\Database\MongoDB::getInstance()
                ->selectCollection('devices');

            foreach ($members as $member) {
                $deviceCollection->updateOne(
                    ['_id' => $member['device_id']],
                    ['$set' => [
                        'cluster_id'   => new ObjectId($clusterId),
                        'cluster_role' => $member['role'],
                        'updated_at'   => new UTCDateTime(),
                    ]]
                );
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    /**
     * Derive overall cluster status from member statuses.
     */
    private function deriveClusterStatus(array $memberStatuses): string
    {
        if (empty($memberStatuses)) {
            return 'unknown';
        }
        if (in_array('online', $memberStatuses, true) && !in_array('unreachable', $memberStatuses, true)) {
            return 'healthy';
        }
        if (in_array('online', $memberStatuses, true)) {
            return 'degraded';
        }
        return 'down';
    }
}
