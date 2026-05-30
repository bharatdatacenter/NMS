<?php

declare(strict_types=1);

namespace NMS\Core\Models\VPN;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\Collection;
use NMS\Core\Models\Secrets\SecretsManagerInterface;

/**
 * VpnTunnelManager — CRUD for vpn_tunnels collection.
 *
 * PSKs are stored in Vault. MongoDB holds only the vault path reference.
 * Tunnel status: up | down | establishing | unknown
 */
class VpnTunnelManager extends Collection
{
    protected string $collectionName = 'vpn_tunnels';

    private SecretsManagerInterface $secrets;

    public function __construct(SecretsManagerInterface $secrets)
    {
        parent::__construct();
        $this->secrets = $secrets;
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $query = [];

        if (!empty($filters['local_gateway_id'])) {
            $query['local_gateway_id'] = new ObjectId($filters['local_gateway_id']);
        }
        if (!empty($filters['status'])) {
            $query['status'] = $filters['status'];
        }
        if (isset($filters['enabled'])) {
            $query['enabled'] = (bool)$filters['enabled'];
        }

        $skip   = ($page - 1) * $perPage;
        $total  = $this->collection()->countDocuments($query);
        $cursor = $this->collection()->find($query, [
            'sort'  => ['created_at' => -1],
            'skip'  => $skip,
            'limit' => $perPage,
        ]);

        return [
            'data'  => array_map($this->normalize(...), iterator_to_array($cursor, false)),
            'total' => $total,
            'page'  => $page,
        ];
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a VPN tunnel.
     *
     * @param array  $data   Tunnel fields
     * @param string $psk    The actual PSK — stored in Vault, NOT in MongoDB
     */
    public function create(array $data, string $psk): array
    {
        $tunnelSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($data['name']));
        $vaultPath  = "nms/vpn/{$tunnelSlug}/psk";
        $this->secrets->put($vaultPath, $psk);

        $doc = [
            'name'             => $data['name'],
            'local_gateway_id' => new ObjectId($data['local_gateway_id']),
            'local_subnets'    => $data['local_subnets'] ?? [],
            'remote_gateway_ip' => $data['remote_gateway_ip'],
            'remote_id'        => $data['remote_id'] ?? null,
            'remote_subnets'   => $data['remote_subnets'] ?? [],

            // Phase 1 (IKE)
            'ike_version'      => $data['ike_version'] ?? '2',
            'ike_encryption'   => $data['ike_encryption'] ?? 'aes256',
            'ike_hash'         => $data['ike_hash'] ?? 'sha256',
            'ike_dh_group'     => (int)($data['ike_dh_group'] ?? 14),
            'ike_lifetime'     => (int)($data['ike_lifetime'] ?? 86400),

            // Phase 2 (ESP)
            'esp_encryption'   => $data['esp_encryption'] ?? 'aes256',
            'esp_hash'         => $data['esp_hash'] ?? 'sha256',
            'esp_pfs_group'    => (int)($data['esp_pfs_group'] ?? 14),
            'esp_lifetime'     => (int)($data['esp_lifetime'] ?? 3600),

            // PSK in Vault
            'vault'            => [
                'provider' => 'hashicorp_vault',
                'path'     => $vaultPath,
                'version'  => 1,
            ],

            'enabled'          => $data['enabled'] ?? true,
            'status'           => 'unknown',
            'last_status_check' => null,
            'synced_to_device' => false,
            'created_at'       => new UTCDateTime(),
        ];

        $result = $this->collection()->insertOne($doc);
        return $this->findById((string)$result->getInsertedId());
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    public function findById(string $id): array
    {
        $doc = $this->collection()->findOne(['_id' => new ObjectId($id)]);
        if ($doc === null) {
            throw new \RuntimeException("VPN tunnel not found: {$id}");
        }
        return $this->normalize($doc);
    }

    // ─── Status Polling ───────────────────────────────────────────────────────

    /**
     * Update the tunnel's status field (called by polling logic or webhook).
     */
    public function updateStatus(string $id, string $status): void
    {
        $allowed = ['up', 'down', 'establishing', 'unknown'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid tunnel status: {$status}");
        }

        $this->collection()->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'status'           => $status,
                'last_status_check' => new UTCDateTime(),
            ]]
        );
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data, ?string $psk = null): array
    {
        $set = [];

        foreach (['name', 'local_subnets', 'remote_gateway_ip', 'remote_id', 'remote_subnets',
                  'ike_version', 'ike_encryption', 'ike_hash', 'ike_dh_group', 'ike_lifetime',
                  'esp_encryption', 'esp_hash', 'esp_pfs_group', 'esp_lifetime', 'enabled'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if ($psk !== null) {
            $existing  = $this->findById($id);
            $vaultPath = $existing['vault']['path'];
            $this->secrets->put($vaultPath, $psk);
            $set['vault.version'] = ($existing['vault']['version'] ?? 1) + 1;
        }

        if (!empty($set)) {
            $this->collection()->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $set]
            );
        }

        return $this->findById($id);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function delete(string $id): bool
    {
        $result = $this->collection()->deleteOne(['_id' => new ObjectId($id)]);
        return $result->getDeletedCount() > 0;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function normalize(object|array $doc): array
    {
        $doc = (array)$doc;
        return [
            'id'                => (string)($doc['_id'] ?? ''),
            'name'              => $doc['name'] ?? '',
            'local_gateway_id'  => (string)($doc['local_gateway_id'] ?? ''),
            'local_subnets'     => (array)($doc['local_subnets'] ?? []),
            'remote_gateway_ip' => $doc['remote_gateway_ip'] ?? '',
            'remote_id'         => $doc['remote_id'] ?? null,
            'remote_subnets'    => (array)($doc['remote_subnets'] ?? []),
            'ike_version'       => $doc['ike_version'] ?? '2',
            'ike_encryption'    => $doc['ike_encryption'] ?? 'aes256',
            'ike_hash'          => $doc['ike_hash'] ?? 'sha256',
            'ike_dh_group'      => (int)($doc['ike_dh_group'] ?? 14),
            'ike_lifetime'      => (int)($doc['ike_lifetime'] ?? 86400),
            'esp_encryption'    => $doc['esp_encryption'] ?? 'aes256',
            'esp_hash'          => $doc['esp_hash'] ?? 'sha256',
            'esp_pfs_group'     => (int)($doc['esp_pfs_group'] ?? 14),
            'esp_lifetime'      => (int)($doc['esp_lifetime'] ?? 3600),
            'vault'             => (array)($doc['vault'] ?? []),
            'enabled'           => (bool)($doc['enabled'] ?? true),
            'status'            => $doc['status'] ?? 'unknown',
            'last_status_check' => $doc['last_status_check'] instanceof UTCDateTime
                ? $doc['last_status_check']->toDateTime()->format('c')
                : null,
            'synced_to_device'  => (bool)($doc['synced_to_device'] ?? false),
            'created_at'        => $doc['created_at'] instanceof UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : null,
        ];
    }
}
