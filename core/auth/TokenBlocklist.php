<?php

declare(strict_types=1);

namespace NMS\Core\Auth;

use Predis\Client as RedisClient;

/**
 * Redis-backed JWT revocation blocklist.
 *
 * Individual revocation: blocklist:{jti} = 1, TTL = remaining token lifetime
 * Bulk revocation:       user:{user_id}:tokens_valid_after = timestamp
 */
class TokenBlocklist
{
    private RedisClient $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Revoke a specific token by its JTI.
     *
     * @param string $jti  JWT ID claim
     * @param int    $ttl  Seconds until the token would have expired anyway
     */
    public function revoke(string $jti, int $ttl): void
    {
        if ($ttl <= 0) {
            return; // Already expired — no need to blocklist
        }
        $this->redis->set('blocklist:' . $jti, '1');
        $this->redis->expire('blocklist:' . $jti, $ttl);
    }

    /**
     * Check if a specific token JTI has been revoked.
     */
    public function isRevoked(string $jti): bool
    {
        return (bool)$this->redis->exists('blocklist:' . $jti);
    }

    /**
     * Bulk revoke all tokens for a user (e.g., on password change).
     * Any token with iat < stored timestamp will be rejected.
     */
    public function revokeAllForUser(string $userId): void
    {
        $key = 'user:' . $userId . ':tokens_valid_after';
        $this->redis->set($key, (string)time());
        // Keep this for 8 days (max refresh token lifetime + buffer)
        $this->redis->expire($key, 8 * 24 * 3600);
    }

    /**
     * Get the "valid after" timestamp for a user. Returns 0 if not set.
     */
    public function getTokensValidAfter(string $userId): int
    {
        $key = 'user:' . $userId . ':tokens_valid_after';
        $value = $this->redis->get($key);
        return $value !== null ? (int)$value : 0;
    }

    /**
     * Check if a token is valid for a user based on bulk revocation.
     *
     * @param string $userId  The user's ID
     * @param int    $iat     Token's issued-at timestamp
     */
    public function isIssuedAfterBulkRevocation(string $userId, int $iat): bool
    {
        $validAfter = $this->getTokensValidAfter($userId);
        return $iat >= $validAfter;
    }

    /**
     * Full revocation check: individual JTI + bulk user revocation.
     */
    public function isTokenValid(string $jti, string $userId, int $iat): bool
    {
        if ($this->isRevoked($jti)) {
            return false;
        }
        if (!$this->isIssuedAfterBulkRevocation($userId, $iat)) {
            return false;
        }
        return true;
    }
}
