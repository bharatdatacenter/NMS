<?php

declare(strict_types=1);

namespace NMS\Core\Models\VPN;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\Collection;

/**
 * VpnUserManager — CRUD for vpn_users collection.
 *
 * Passwords are hashed with Argon2id — never stored as plaintext or encrypted.
 * This is intentional: password recovery requires a reset, not decryption.
 */
class VpnUserManager extends Collection
{
    protected string $collectionName = 'vpn_users';

    // ─── List ─────────────────────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $query = [];

        if (!empty($filters['gateway_id'])) {
            $query['gateway_id'] = new ObjectId($filters['gateway_id']);
        }
        if (isset($filters['enabled'])) {
            $query['enabled'] = (bool)$filters['enabled'];
        }
        if (!empty($filters['username'])) {
            $query['username'] = ['$regex' => $filters['username'], '$options' => 'i'];
        }

        $skip   = ($page - 1) * $perPage;
        $total  = $this->collection()->countDocuments($query);
        $cursor = $this->collection()->find($query, [
            'sort'       => ['username' => 1],
            'skip'       => $skip,
            'limit'      => $perPage,
            'projection' => ['password_hash' => 0],  // Never return hash
        ]);

        return [
            'data'  => array_map($this->normalize(...), iterator_to_array($cursor, false)),
            'total' => $total,
            'page'  => $page,
        ];
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a VPN user.
     * Password is hashed with Argon2id immediately — plaintext is not retained.
     */
    public function create(array $data, string $createdByUserId): array
    {
        if (empty($data['password'])) {
            throw new \InvalidArgumentException('Password is required for VPN user creation');
        }

        // Validate gateway exists
        $gatewayId = new ObjectId($data['gateway_id']);

        // Hash with Argon2id
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,   // 64 MiB
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        if ($passwordHash === false) {
            throw new \RuntimeException('Failed to hash password');
        }

        // Check username uniqueness within gateway
        $existing = $this->collection()->findOne([
            'gateway_id' => $gatewayId,
            'username'   => $data['username'],
        ]);
        if ($existing !== null) {
            throw new \RuntimeException("Username already exists on this gateway: {$data['username']}");
        }

        $doc = [
            'gateway_id'          => $gatewayId,
            'username'            => $data['username'],
            'password_hash'       => $passwordHash,  // Argon2id hash
            'certificate_cn'      => $data['certificate_cn'] ?? null,
            'assigned_ip'         => $data['assigned_ip'] ?? null,
            'allowed_subnets'     => $data['allowed_subnets'] ?? [],
            'bandwidth_limit_kbps' => $data['bandwidth_limit_kbps'] ?? null,
            'concurrent_connections' => (int)($data['concurrent_connections'] ?? 1),
            'enabled'             => $data['enabled'] ?? true,
            'expires_at'          => !empty($data['expires_at'])
                ? new UTCDateTime(strtotime($data['expires_at']) * 1000)
                : null,
            'last_connected'      => null,
            'total_bytes_in'      => 0,
            'total_bytes_out'     => 0,
            'created_at'          => new UTCDateTime(),
            'created_by'          => new ObjectId($createdByUserId),
        ];

        $result = $this->collection()->insertOne($doc);
        return $this->findById((string)$result->getInsertedId());
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    public function findById(string $id): array
    {
        $doc = $this->collection()->findOne(
            ['_id' => new ObjectId($id)],
            ['projection' => ['password_hash' => 0]]
        );
        if ($doc === null) {
            throw new \RuntimeException("VPN user not found: {$id}");
        }
        return $this->normalize($doc);
    }

    // ─── Password Verification ────────────────────────────────────────────────

    /**
     * Verify a user's password against stored Argon2id hash.
     * Returns true if password matches, false otherwise.
     */
    public function verifyPassword(string $id, string $plaintext): bool
    {
        $doc = $this->collection()->findOne(['_id' => new ObjectId($id)]);
        if ($doc === null) {
            return false;
        }
        $hash = (array)$doc;
        return password_verify($plaintext, $hash['password_hash'] ?? '');
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(string $id, array $data): array
    {
        $set = [];

        foreach (['assigned_ip', 'allowed_subnets', 'bandwidth_limit_kbps',
                  'concurrent_connections', 'enabled', 'certificate_cn'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $set['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 1,
            ]);
        }

        if (!empty($data['expires_at'])) {
            $set['expires_at'] = new UTCDateTime(strtotime($data['expires_at']) * 1000);
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
            'id'                    => (string)($doc['_id'] ?? ''),
            'gateway_id'            => (string)($doc['gateway_id'] ?? ''),
            'username'              => $doc['username'] ?? '',
            'certificate_cn'        => $doc['certificate_cn'] ?? null,
            'assigned_ip'           => $doc['assigned_ip'] ?? null,
            'allowed_subnets'       => (array)($doc['allowed_subnets'] ?? []),
            'bandwidth_limit_kbps'  => $doc['bandwidth_limit_kbps'] ?? null,
            'concurrent_connections' => (int)($doc['concurrent_connections'] ?? 1),
            'enabled'               => (bool)($doc['enabled'] ?? true),
            'expires_at'            => $doc['expires_at'] instanceof UTCDateTime
                ? $doc['expires_at']->toDateTime()->format('c')
                : null,
            'last_connected'        => $doc['last_connected'] instanceof UTCDateTime
                ? $doc['last_connected']->toDateTime()->format('c')
                : null,
            'total_bytes_in'        => (int)($doc['total_bytes_in'] ?? 0),
            'total_bytes_out'       => (int)($doc['total_bytes_out'] ?? 0),
            'created_at'            => $doc['created_at'] instanceof UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : null,
            'created_by'            => $doc['created_by'] ? (string)$doc['created_by'] : null,
        ];
    }
}
