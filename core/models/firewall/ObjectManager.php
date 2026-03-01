<?php

declare(strict_types=1);

namespace NMS\Core\Models\Firewall;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;

/**
 * ObjectManager
 *
 * CRUD for:
 *   - firewall_address_objects  (hosts, subnets, ranges, FQDNs)
 *   - firewall_address_groups   (groups of address objects)
 *   - firewall_service_objects  (TCP/UDP/ICMP port definitions)
 *   - firewall_vips             (IPv4 DNAT mappings — ALWAYS ip_version: "ipv4")
 *
 * VIPs are IPv4-only by design — IPv6 addresses are globally routable and
 * never need NAT.
 */
class ObjectManager
{
    private \MongoDB\Collection $addresses;
    private \MongoDB\Collection $groups;
    private \MongoDB\Collection $services;
    private \MongoDB\Collection $vips;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->addresses = $db->selectCollection('firewall_address_objects');
        $this->groups    = $db->selectCollection('firewall_address_groups');
        $this->services  = $db->selectCollection('firewall_service_objects');
        $this->vips      = $db->selectCollection('firewall_vips');
    }

    // ─── Address Objects ──────────────────────────────────────────────────────

    public function listAddresses(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = [];
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['type'])) {
            $filter['type'] = $filters['type'];
        }

        $total  = $this->addresses->countDocuments($filter);
        $cursor = $this->addresses->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['name' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function createAddress(array $data): string
    {
        if (empty($data['ip_version'])) {
            throw new \InvalidArgumentException('ip_version is required');
        }
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name is required');
        }

        $doc = [
            'device_id'        => isset($data['device_id']) ? new ObjectId($data['device_id']) : null,
            'cluster_id'       => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'name'             => $data['name'],
            'type'             => $data['type'] ?? 'host',
            'ip_version'       => $data['ip_version'],
            'address'          => $data['address'] ?? null,
            'start_ip'         => $data['start_ip'] ?? null,
            'end_ip'           => $data['end_ip'] ?? null,
            'country_code'     => $data['country_code'] ?? null,
            'description'      => $data['description'] ?? null,
            'synced_to_device' => false,
            'created_at'       => new UTCDateTime(),
        ];

        $result = $this->addresses->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findAddressById(string $id): ?array
    {
        $doc = $this->addresses->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }

    // ─── Service Objects ──────────────────────────────────────────────────────

    public function listServices(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = [];
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['protocol'])) {
            $filter['protocol'] = $filters['protocol'];
        }

        $total  = $this->services->countDocuments($filter);
        $cursor = $this->services->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['name' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function createService(array $data): string
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name is required');
        }

        $doc = [
            'device_id'        => isset($data['device_id']) ? new ObjectId($data['device_id']) : null,
            'cluster_id'       => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'name'             => $data['name'],
            'protocol'         => $data['protocol'] ?? 'tcp',
            'port_start'       => isset($data['port_start']) ? (int) $data['port_start'] : null,
            'port_end'         => isset($data['port_end']) ? (int) $data['port_end'] : null,
            'icmp_type'        => $data['icmp_type'] ?? null,
            'description'      => $data['description'] ?? null,
            'is_system'        => (bool) ($data['is_system'] ?? false),
            'synced_to_device' => false,
            'created_at'       => new UTCDateTime(),
        ];

        $result = $this->services->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findServiceById(string $id): ?array
    {
        $doc = $this->services->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }

    // ─── VIPs (IPv4 only) ─────────────────────────────────────────────────────

    public function listVips(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = ['ip_version' => 'ipv4'];  // VIPs are always IPv4
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['cluster_id'])) {
            $filter['cluster_id'] = new ObjectId($filters['cluster_id']);
        }

        $total  = $this->vips->countDocuments($filter);
        $cursor = $this->vips->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['name' => 1],
        ]);

        return [
            'data'      => iterator_to_array($cursor, false),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public function createVip(array $data): string
    {
        if (empty($data['device_id'])) {
            throw new \InvalidArgumentException('device_id is required');
        }
        if (empty($data['external_ip'])) {
            throw new \InvalidArgumentException('external_ip is required');
        }
        if (empty($data['mapped_ip'])) {
            throw new \InvalidArgumentException('mapped_ip is required');
        }

        $doc = [
            'device_id'        => new ObjectId($data['device_id']),
            'cluster_id'       => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'name'             => $data['name'] ?? "VIP-{$data['external_ip']}",
            'ip_version'       => 'ipv4',    // Always IPv4 for VIPs
            'external_ip'      => $data['external_ip'],
            'external_port'    => isset($data['external_port']) ? (int) $data['external_port'] : null,
            'mapped_ip'        => $data['mapped_ip'],
            'mapped_port'      => isset($data['mapped_port']) ? (int) $data['mapped_port'] : null,
            'protocol'         => $data['protocol'] ?? 'tcp',
            'comment'          => $data['comment'] ?? null,
            'synced_to_device' => false,
            'created_at'       => new UTCDateTime(),
        ];

        $result = $this->vips->insertOne($doc);
        return (string) $result->getInsertedId();
    }

    public function findVipById(string $id): ?array
    {
        $doc = $this->vips->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }
}
