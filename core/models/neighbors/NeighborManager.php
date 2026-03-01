<?php

declare(strict_types=1);

namespace NMS\Core\Models\Neighbors;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * NeighborManager
 *
 * CRUD for the `neighbor_entries` collection.
 * Handles both ARP (IPv4, protocol="arp") and NDP (IPv6, protocol="ndp").
 * Writes to mac_address_registry on every create.
 */
class NeighborManager
{
    private \MongoDB\Collection $neighbors;
    private \MongoDB\Collection $macRegistry;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->neighbors   = $db->selectCollection('neighbor_entries');
        $this->macRegistry = $db->selectCollection('mac_address_registry');
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = [];
        if (!empty($filters['protocol'])) {
            $filter['protocol'] = $filters['protocol'];
        }
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }

        $total  = $this->neighbors->countDocuments($filter);
        $cursor = $this->neighbors->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['ip_address' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(array $data, string $createdBy): string
    {
        if (empty($data['protocol']) || !in_array($data['protocol'], ['arp', 'ndp'], true)) {
            throw new \InvalidArgumentException("protocol must be 'arp' or 'ndp'");
        }
        if (empty($data['device_id'])) {
            throw new \InvalidArgumentException('device_id is required');
        }
        if (empty($data['ip_address'])) {
            throw new \InvalidArgumentException('ip_address is required');
        }
        if (empty($data['mac_address'])) {
            throw new \InvalidArgumentException('mac_address is required');
        }

        $ipVersion = $data['protocol'] === 'arp' ? 'ipv4' : 'ipv6';
        $mac       = strtoupper($data['mac_address']);
        $now       = new UTCDateTime();

        $doc = [
            'device_id'        => new ObjectId($data['device_id']),
            'cluster_id'       => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'protocol'         => $data['protocol'],
            'ip_version'       => $data['ip_version'] ?? $ipVersion,
            'ip_address'       => $data['ip_address'],
            'mac_address'      => $mac,
            'interface_name'   => $data['interface_name'] ?? null,
            'entry_type'       => $data['entry_type'] ?? 'static',
            'ip_assignment_id' => isset($data['ip_assignment_id']) ? new ObjectId($data['ip_assignment_id']) : null,
            'synced_to_device' => false,
            'device_entry_id'  => null,
            'last_synced'      => null,
            'enabled'          => true,
            'created_at'       => $now,
            'created_by'       => new ObjectId($createdBy),
        ];

        $result = $this->neighbors->insertOne($doc);
        $id     = (string) $result->getInsertedId();

        $this->upsertMacRegistry($mac, $data, $now);

        return $id;
    }

    // ─── Find ─────────────────────────────────────────────────────────────────

    public function findById(string $id): ?array
    {
        $doc = $this->neighbors->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function delete(string $id): bool
    {
        $result = $this->neighbors->deleteOne(['_id' => new ObjectId($id)]);
        return $result->getDeletedCount() > 0;
    }

    // ─── Sync Status ──────────────────────────────────────────────────────────

    public function updateSyncStatus(string $id, bool $synced, ?string $deviceEntryId = null, ?string $error = null): void
    {
        $update = [
            'synced_to_device' => $synced,
            'last_synced'      => new UTCDateTime(),
        ];
        if ($deviceEntryId !== null) {
            $update['device_entry_id'] = $deviceEntryId;
        }

        $this->neighbors->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $update]
        );
    }

    // ─── Device Neighbors ─────────────────────────────────────────────────────

    public function listByDevice(string $deviceId): array
    {
        $cursor = $this->neighbors->find(
            ['device_id' => new ObjectId($deviceId)],
            ['sort' => ['ip_address' => 1]]
        );
        return iterator_to_array($cursor, false);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function upsertMacRegistry(string $mac, array $data, UTCDateTime $now): void
    {
        $this->macRegistry->updateOne(
            ['mac_address' => $mac],
            [
                '$set' => [
                    'last_seen'   => $now,
                    'device_type' => $data['device_type'] ?? 'server',
                    'owner'       => $data['owner'] ?? null,
                    'notes'       => $data['notes'] ?? null,
                ],
                '$setOnInsert' => [
                    'mac_address' => $mac,
                    'vendor'      => $this->lookupOui($mac),
                    'first_seen'  => $now,
                ],
            ],
            ['upsert' => true]
        );
    }

    /**
     * Minimal OUI prefix lookup — extend via full OUI database in production.
     */
    private function lookupOui(string $mac): string
    {
        $hex    = strtoupper(preg_replace('/[^A-F0-9]/i', '', $mac));
        $prefix = substr($hex, 0, 6);

        $knownOui = [
            '000C29' => 'VMware, Inc.',
            '0050AB' => 'Dell Inc.',
            '001A4B' => 'Cisco Systems, Inc.',
            '3C2C30' => 'Hewlett Packard Enterprise',
            '001B21' => 'Intel Corporation',
        ];

        return $knownOui[$prefix] ?? 'Unknown';
    }
}
