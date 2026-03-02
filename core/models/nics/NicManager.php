<?php

declare(strict_types=1);

namespace NMS\Core\Models\Nics;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * NicManager
 *
 * Manages the server_nics collection — NMS's network context for each server NIC.
 * IMS owns physical NIC hardware; NMS owns switch port, VLAN, cable, and IP links.
 *
 * Key operations:
 *   syncFromWebhook()       — called on server.nic_change webhook from IMS
 *   updatePortAssignment()  — links a NIC to a switch port/device/cable
 *   linkIPAssignment()      — links a NIC to an IP assignment record
 */
class NicManager
{
    private \MongoDB\Collection $nics;

    public function __construct()
    {
        $this->nics = MongoDB::getInstance()->selectCollection('server_nics');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Webhook sync
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sync server NICs from an IMS server.nic_change webhook payload.
     * Upserts each NIC by MAC address, preserving NMS-owned fields.
     *
     * @param string $imsServerId  IMS server UUID
     * @param array  $nics         NIC list from IMS webhook (each has name, mac_address, nic_index, ...)
     */
    public function syncFromWebhook(string $imsServerId, array $nics): void
    {
        foreach ($nics as $nic) {
            $mac     = strtolower((string)($nic['mac_address'] ?? ''));
            $nicName = (string)($nic['name'] ?? $nic['nic_name'] ?? '');

            if ($mac === '') {
                continue; // Cannot upsert without MAC
            }

            $setOnInsert = [
                'connected_to'    => null,
                'vlan_id'         => null,
                'vlan_name'       => null,
                'access_mode'     => 'access',
                'ip_assignments'  => [],
                'is_bond_member'  => false,
                'bond_master'     => null,
                'status'          => 'unknown',
                'created_at'      => new UTCDateTime(),
            ];

            $setFields = [
                'ims_server_id' => $imsServerId,
                'server_name'   => (string)($nic['server_name'] ?? ''),
                'nic_name'      => $nicName,
                'mac_address'   => $mac,
                'nic_index'     => (int)($nic['nic_index'] ?? 0),
                'last_synced'   => new UTCDateTime(),
                'updated_at'    => new UTCDateTime(),
            ];

            $this->nics->updateOne(
                ['mac_address' => $mac],
                [
                    '$set'         => $setFields,
                    '$setOnInsert' => $setOnInsert,
                ],
                ['upsert' => true]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Connectivity assignment
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update a NIC's network connectivity (switch port, device, cable).
     *
     * @param string $nicId      NIC document _id
     * @param array  $connectivity {
     *   device_id, device_name, port_name, cable_id?,
     *   vlan_id?, vlan_name?, access_mode?
     * }
     */
    public function updatePortAssignment(string $nicId, array $connectivity): void
    {
        $set = [
            'connected_to' => [
                'device_id'   => isset($connectivity['device_id'])
                    ? new ObjectId($connectivity['device_id']) : null,
                'device_name' => (string)($connectivity['device_name'] ?? ''),
                'port_name'   => (string)($connectivity['port_name'] ?? ''),
                'cable_id'    => isset($connectivity['cable_id'])
                    ? new ObjectId($connectivity['cable_id']) : null,
            ],
            'updated_at' => new UTCDateTime(),
        ];

        if (array_key_exists('vlan_id', $connectivity)) {
            $set['vlan_id']   = (int)$connectivity['vlan_id'];
        }
        if (array_key_exists('vlan_name', $connectivity)) {
            $set['vlan_name'] = (string)$connectivity['vlan_name'];
        }
        if (array_key_exists('access_mode', $connectivity)) {
            $allowedModes = ['access', 'trunk'];
            if (in_array($connectivity['access_mode'], $allowedModes, true)) {
                $set['access_mode'] = $connectivity['access_mode'];
            }
        }

        $result = $this->nics->updateOne(['_id' => new ObjectId($nicId)], ['$set' => $set]);
        if ($result->getMatchedCount() === 0) {
            throw new \RuntimeException("NIC {$nicId} not found");
        }
    }

    /**
     * Link an IP assignment to a NIC's ip_assignments array.
     * Adds or updates the entry for the given assignment_id.
     *
     * @param string $nicId        NIC document _id
     * @param string $assignmentId ip_assignments document _id
     * @param array  $meta         { ip_address, ip_version, prefix_length, gateway, assignment_type }
     */
    public function linkIPAssignment(string $nicId, string $assignmentId, array $meta = []): void
    {
        // Remove existing entry for this assignment_id, then push new one
        $assignOid = new ObjectId($assignmentId);

        $this->nics->updateOne(
            ['_id' => new ObjectId($nicId)],
            ['$pull' => ['ip_assignments' => ['assignment_id' => $assignOid]]]
        );

        $this->nics->updateOne(
            ['_id' => new ObjectId($nicId)],
            [
                '$push' => ['ip_assignments' => array_merge([
                    'assignment_id'   => $assignOid,
                    'ip_address'      => (string)($meta['ip_address'] ?? ''),
                    'ip_version'      => (string)($meta['ip_version'] ?? 'ipv4'),
                    'prefix_length'   => (int)($meta['prefix_length'] ?? 24),
                    'gateway'         => (string)($meta['gateway'] ?? ''),
                    'assignment_type' => (string)($meta['assignment_type'] ?? 'l2'),
                ])],
                '$set'  => ['updated_at' => new UTCDateTime()],
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Read operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List NICs with optional filters.
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filter = [];

        if (!empty($filters['ims_server_id'])) {
            $filter['ims_server_id'] = $filters['ims_server_id'];
        }
        if (!empty($filters['device_id'])) {
            $filter['connected_to.device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['vlan_id'])) {
            $filter['vlan_id'] = (int)$filters['vlan_id'];
        }
        if (!empty($filters['status'])) {
            $filter['status'] = $filters['status'];
        }

        $total  = $this->nics->countDocuments($filter);
        $cursor = $this->nics->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['ims_server_id' => 1, 'nic_index' => 1],
        ]);

        return [
            'data'      => array_map([$this, 'docToArray'], $cursor->toArray()),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Find a NIC by ID.
     */
    public function findById(string $id): ?array
    {
        $doc = $this->nics->findOne(['_id' => new ObjectId($id)]);
        return $doc ? $this->docToArray($doc) : null;
    }

    /**
     * Find all NICs for an IMS server.
     */
    public function findByServerId(string $imsServerId): array
    {
        $cursor = $this->nics->find(
            ['ims_server_id' => $imsServerId],
            ['sort' => ['nic_index' => 1]]
        );
        return array_map([$this, 'docToArray'], $cursor->toArray());
    }

    /**
     * Find all NICs connected to a switch device.
     */
    public function findBySwitchDeviceId(string $deviceId): array
    {
        $cursor = $this->nics->find(
            ['connected_to.device_id' => new ObjectId($deviceId)],
            ['sort' => ['connected_to.port_name' => 1]]
        );
        return array_map([$this, 'docToArray'], $cursor->toArray());
    }

    /**
     * Update mutable NIC fields (status, vlan_id, access_mode, is_bond_member, bond_master).
     */
    public function update(string $id, array $data): bool
    {
        $allowed = ['status', 'vlan_id', 'vlan_name', 'access_mode', 'is_bond_member', 'bond_master'];
        $set     = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        if (empty($set)) {
            return false;
        }
        $set['updated_at'] = new UTCDateTime();

        $result = $this->nics->updateOne(['_id' => new ObjectId($id)], ['$set' => $set]);
        return $result->getModifiedCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

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
            $arr['id'] = (string)$arr['_id'];
            unset($arr['_id']);
        }
        return $arr;
    }
}
