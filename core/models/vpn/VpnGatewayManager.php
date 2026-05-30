<?php

declare(strict_types=1);

namespace NMS\Core\Models\VPN;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\Collection;
use NMS\Core\Models\Secrets\SecretsManagerInterface;

/**
 * VpnGatewayManager — CRUD for vpn_gateways collection.
 *
 * PSKs are stored in Vault. MongoDB holds only the vault path reference.
 * Never store the actual PSK in MongoDB.
 */
class VpnGatewayManager extends Collection
{
    protected string $collectionName = 'vpn_gateways';

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

        if (!empty($filters['device_id'])) {
            $query['device_id'] = new ObjectId($filters['device_id']);
        }
        if (!empty($filters['gateway_type'])) {
            $query['gateway_type'] = $filters['gateway_type'];
        }
        if (isset($filters['enabled'])) {
            $query['enabled'] = (bool)$filters['enabled'];
        }

        $skip    = ($page - 1) * $perPage;
        $total   = $this->collection()->countDocuments($query);
        $cursor  = $this->collection()->find($query, [
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
     * Create a VPN gateway.
     *
     * @param array  $data   Gateway fields
     * @param string $psk    The actual PSK — stored in Vault, NOT in MongoDB
     */
    public function create(array $data, string $psk): array
    {
        $deviceId  = new ObjectId($data['device_id']);
        $clusterId = !empty($data['cluster_id']) ? new ObjectId($data['cluster_id']) : null;

        // Store PSK in Vault
        $gatewaySlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($data['name']));
        $vaultPath   = "nms/vpn/{$gatewaySlug}/psk";
        $this->secrets->put($vaultPath, $psk);

        $doc = [
            'device_id'        => $deviceId,
            'cluster_id'       => $clusterId,
            'name'             => $data['name'],
            'gateway_type'     => $data['gateway_type'] ?? 'ipsec',
            'public_ip'        => $data['public_ip'],
            'public_port'      => (int)($data['public_port'] ?? 500),
            'auth_type'        => $data['auth_type'] ?? 'psk',
            'vault'            => [
                'provider' => 'hashicorp_vault',
                'path'     => $vaultPath,
                'version'  => 1,
            ],
            'client_ip_pool_id' => !empty($data['client_ip_pool_id'])
                ? new ObjectId($data['client_ip_pool_id'])
                : null,
            'dns_servers'      => $data['dns_servers'] ?? [],
            'enabled'          => $data['enabled'] ?? true,
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
            throw new \RuntimeException("VPN gateway not found: {$id}");
        }
        return $this->normalize($doc);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data, ?string $psk = null): array
    {
        $set = [];

        foreach (['name', 'public_ip', 'public_port', 'auth_type', 'dns_servers', 'enabled'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if ($psk !== null) {
            // Re-store PSK in Vault with new version
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
            'id'               => (string)($doc['_id'] ?? ''),
            'device_id'        => (string)($doc['device_id'] ?? ''),
            'cluster_id'       => $doc['cluster_id'] ? (string)$doc['cluster_id'] : null,
            'name'             => $doc['name'] ?? '',
            'gateway_type'     => $doc['gateway_type'] ?? 'ipsec',
            'public_ip'        => $doc['public_ip'] ?? '',
            'public_port'      => (int)($doc['public_port'] ?? 500),
            'auth_type'        => $doc['auth_type'] ?? 'psk',
            'vault'            => (array)($doc['vault'] ?? []),
            'client_ip_pool_id' => $doc['client_ip_pool_id'] ? (string)$doc['client_ip_pool_id'] : null,
            'dns_servers'      => (array)($doc['dns_servers'] ?? []),
            'enabled'          => (bool)($doc['enabled'] ?? true),
            'created_at'       => $doc['created_at'] instanceof UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : null,
        ];
    }
}
