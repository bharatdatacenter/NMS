<?php

declare(strict_types=1);

/**
 * POST /api/auth/logout
 *
 * Adds the token's jti to the Redis blocklist.
 * Both access token and (if provided) refresh token are revoked.
 */

use NMS\Core\Auth\JWTHelper;
use NMS\Core\Auth\TokenBlocklist;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use Predis\Client as RedisClient;

try {
    // $request['user'] is already set by AuthMiddleware
    $claims = $request['user'] ?? null;
    if (!$claims) {
        Response::unauthorized('Not authenticated');
    }

    $secrets      = new VaultSecretsManager();
    $jwt          = new JWTHelper($secrets);
    $redisConfig  = require dirname(__DIR__, 3) . '/core/config/redis.php';
    $redis        = new RedisClient($redisConfig);
    $blocklist    = new TokenBlocklist($redis);

    // Revoke access token
    $jti = $claims->jti ?? '';
    $exp = $claims->exp ?? time();
    $ttl = max(0, $exp - time());
    if ($jti) {
        $blocklist->revoke($jti, $ttl);
    }

    // Revoke refresh token if provided
    $refreshToken = $body['refresh_token'] ?? null;
    if ($refreshToken) {
        try {
            $refreshClaims = $jwt->validate($refreshToken);
            if (($refreshClaims->type ?? '') === 'refresh') {
                $refreshJti = $refreshClaims->jti ?? '';
                $refreshTtl = max(0, ($refreshClaims->exp ?? time()) - time());
                if ($refreshJti) {
                    $blocklist->revoke($refreshJti, $refreshTtl);
                }
            }
        } catch (\Exception) {
            // Ignore invalid refresh token — access token is already revoked
        }
    }

    Response::json(['message' => 'Logged out successfully']);

} catch (\Exception $e) {
    Response::error('Logout failed: ' . $e->getMessage(), 500);
}
