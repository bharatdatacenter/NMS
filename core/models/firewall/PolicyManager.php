<?php

declare(strict_types=1);

namespace NMS\Core\Models\Firewall;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Models\Devices\DeviceFactory;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * PolicyManager
 *
 * CRUD for the `firewall_policies` collection.
 * ip_version is REQUIRED on all policies.
 * Writes to firewall_policy_history on every create/modify/delete.
 * Sync pushes to cluster.management_ip when device is clustered.
 *
 * IPv6 policies MUST have nat_enabled=false and vip_id=null — enforced here.
 */
class PolicyManager
{
    private \MongoDB\Collection $policies;
    private \MongoDB\Collection $history;
    private \MongoDB\Collection $clusters;
    private DeviceManager $deviceManager;

    public function __construct()
    {
        $db = MongoDB::getInstance();
        $this->policies      = $db->selectCollection('firewall_policies');
        $this->history       = $db->selectCollection('firewall_policy_history');
        $this->clusters      = $db->selectCollection('device_clusters');
        $this->deviceManager = new DeviceManager();
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $filter = [];
        if (!empty($filters['ip_version'])) {
            $filter['ip_version'] = $filters['ip_version'];
        }
        if (!empty($filters['device_id'])) {
            $filter['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['cluster_id'])) {
            $filter['cluster_id'] = new ObjectId($filters['cluster_id']);
        }
        if (!empty($filters['direction'])) {
            $filter['direction'] = $filters['direction'];
        }
        if (!empty($filters['action'])) {
            $filter['action'] = $filters['action'];
        }

        $total  = $this->policies->countDocuments($filter);
        $cursor = $this->policies->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => ['sequence' => 1],
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
        if (empty($data['ip_version'])) {
            throw new \InvalidArgumentException('ip_version is required');
        }
        if (empty($data['device_id'])) {
            throw new \InvalidArgumentException('device_id is required');
        }

        // Enforce IPv6 constraints: no VIP, no NAT
        if ($data['ip_version'] === 'ipv6') {
            $data['vip_id']      = null;
            $data['mapped_ip']   = null;
            $data['mapped_port'] = null;
            $data['nat_enabled'] = false;
            $data['nat_type']    = null;
        }

        $now = new UTCDateTime();
        $doc = [
            'device_id'            => new ObjectId($data['device_id']),
            'cluster_id'           => isset($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null,
            'policy_id'            => $data['policy_id'] ?? null,
            'name'                 => $data['name'],
            'sequence'             => (int) ($data['sequence'] ?? 0),
            'ip_version'           => $data['ip_version'],
            'direction'            => $data['direction'] ?? 'inbound',
            'source_zone'          => $data['source_zone'] ?? null,
            'destination_zone'     => $data['destination_zone'] ?? null,
            'source_addresses'     => $this->toObjectIds($data['source_addresses'] ?? []),
            'destination_addresses' => $this->toObjectIds($data['destination_addresses'] ?? []),
            'services'             => $this->toObjectIds($data['services'] ?? []),
            'vip_id'               => isset($data['vip_id']) && $data['vip_id'] ? new ObjectId($data['vip_id']) : null,
            'mapped_ip'            => $data['mapped_ip'] ?? null,
            'mapped_port'          => isset($data['mapped_port']) ? (int) $data['mapped_port'] : null,
            'nat_enabled'          => (bool) ($data['nat_enabled'] ?? false),
            'nat_type'             => $data['nat_type'] ?? null,
            'action'               => $data['action'] ?? 'allow',
            'log_traffic'          => $data['log_traffic'] ?? 'none',
            'schedule'             => $data['schedule'] ?? null,
            'enabled'              => true,
            'comments'             => $data['comments'] ?? null,
            'ip_assignment_id'     => isset($data['ip_assignment_id']) ? new ObjectId($data['ip_assignment_id']) : null,
            'server_id'            => $data['server_id'] ?? null,
            'synced_to_device'     => false,
            'last_synced'          => null,
            'sync_error'           => null,
            'created_at'           => $now,
            'updated_at'           => $now,
            'created_by'           => new ObjectId($createdBy),
        ];

        $result = $this->policies->insertOne($doc);
        $id     = (string) $result->getInsertedId();

        $this->writeHistory($id, 'created', null, $doc, $createdBy, $data['comments'] ?? null);

        return $id;
    }

    // ─── Find ─────────────────────────────────────────────────────────────────

    public function findById(string $id): ?array
    {
        $doc = $this->policies->findOne(['_id' => new ObjectId($id)]);
        return $doc ? iterator_to_array($doc) : null;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data, string $updatedBy): bool
    {
        $existing = $this->findById($id);
        if (!$existing) {
            return false;
        }

        // Enforce IPv6 constraints on update
        $version = $data['ip_version'] ?? $existing['ip_version'];
        if ($version === 'ipv6') {
            $data['vip_id']      = null;
            $data['nat_enabled'] = false;
            $data['nat_type']    = null;
        }

        $now    = new UTCDateTime();
        $scalar = ['name', 'sequence', 'direction', 'source_zone', 'destination_zone',
                   'action', 'log_traffic', 'schedule', 'comments', 'enabled',
                   'mapped_ip', 'mapped_port', 'nat_enabled', 'nat_type'];
        $update = ['updated_at' => $now];

        foreach ($scalar as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (array_key_exists('vip_id', $data)) {
            $update['vip_id'] = $data['vip_id'] ? new ObjectId($data['vip_id']) : null;
        }
        if (isset($data['source_addresses'])) {
            $update['source_addresses'] = $this->toObjectIds($data['source_addresses']);
        }
        if (isset($data['destination_addresses'])) {
            $update['destination_addresses'] = $this->toObjectIds($data['destination_addresses']);
        }
        if (isset($data['services'])) {
            $update['services'] = $this->toObjectIds($data['services']);
        }

        $this->policies->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $update]
        );

        $this->writeHistory($id, 'modified', $existing, array_merge($existing, $update), $updatedBy, null);

        return true;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function delete(string $id, string $deletedBy): bool
    {
        $doc = $this->findById($id);
        if (!$doc) {
            return false;
        }

        $this->policies->deleteOne(['_id' => new ObjectId($id)]);
        $this->writeHistory($id, 'deleted', $doc, null, $deletedBy, null);

        return true;
    }

    // ─── Sync to Device ───────────────────────────────────────────────────────

    public function syncToDevice(string $id): bool
    {
        $policy = $this->findById($id);
        if (!$policy) {
            throw new \RuntimeException("Policy {$id} not found");
        }

        $device = $this->deviceManager->findById((string) $policy['device_id']);
        if (!$device) {
            throw new \RuntimeException("Device not found for policy {$id}");
        }

        $device  = $this->resolveClusterDevice($device, $policy);
        $adapter = DeviceFactory::create($device, $this->buildSecretsManager());
        if (!$adapter) {
            throw new \RuntimeException("No adapter for vendor '{$device['vendor']}'");
        }

        $adapter->connect();
        try {
            $ok = $adapter->addFirewallRule($policy);
        } finally {
            $adapter->disconnect();
        }

        $this->policies->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'synced_to_device' => $ok,
                'last_synced'      => new UTCDateTime(),
                'sync_error'       => $ok ? null : 'Adapter returned false',
                'updated_at'       => new UTCDateTime(),
            ]]
        );

        return $ok;
    }

    // ─── Reorder ──────────────────────────────────────────────────────────────

    /**
     * Reorder policies — accepts array of ['id' => ..., 'sequence' => ...].
     */
    public function reorder(array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['id']) || !isset($item['sequence'])) {
                continue;
            }
            $this->policies->updateOne(
                ['_id' => new ObjectId($item['id'])],
                ['$set' => ['sequence' => (int) $item['sequence'], 'updated_at' => new UTCDateTime()]]
            );
        }
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function resolveClusterDevice(array $device, array $policy): array
    {
        $clusterId = $policy['cluster_id'] ?? $device['cluster_id'] ?? null;
        if (!$clusterId) {
            return $device;
        }

        $cluster = $this->clusters->findOne(['_id' => new ObjectId((string) $clusterId)]);
        if ($cluster && !empty($cluster['management_ip'])) {
            $device['ip_address'] = $cluster['management_ip'];
        }

        return $device;
    }

    private function toObjectIds(array $ids): array
    {
        return array_map(fn ($id) => new ObjectId((string) $id), $ids);
    }

    private function writeHistory(
        string  $policyId,
        string  $action,
        ?array  $previousState,
        ?array  $newState,
        string  $changedBy,
        ?string $reason
    ): void {
        $this->history->insertOne([
            'policy_id'      => new ObjectId($policyId),
            'action'         => $action,
            'previous_state' => $previousState,
            'new_state'      => $newState,
            'changed_by'     => new ObjectId($changedBy),
            'changed_at'     => new UTCDateTime(),
            'reason'         => $reason,
        ]);
    }

    private function buildSecretsManager(): \NMS\Core\Models\Secrets\SecretsManagerInterface
    {
        $config = require dirname(__DIR__, 3) . '/core/config/vault.php';
        if (!empty($config['enabled'])) {
            return new VaultSecretsManager($config);
        }
        return new AppEncryptedSecretsManager();
    }
}
