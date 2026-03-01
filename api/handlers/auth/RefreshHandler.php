<?php

declare(strict_types=1);

/**
 * POST /api/auth/refresh
 *
 * One-time refresh token rotation.
 * Old refresh token is immediately revoked after use.
 */

use NMS\Core\Auth\JWTHelper;
use NMS\Core\Auth\TokenBlocklist;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use Predis\Client as RedisClient;

try {
    $refreshToken = $body['refresh_token'] ?? null;
    if (!$refreshToken) {
        Response::unprocessable('refresh_token is required');
    }

    $secrets   = new VaultSecretsManager();
    $jwt       = new JWTHelper($secrets);
    $redisConfig = require dirname(__DIR__, 3) . '/core/config/redis.php';
    $redis     = new RedisClient($redisConfig);
    $blocklist = new TokenBlocklist($redis);

    // Validate refresh token
    $claims = $jwt->validate($refreshToken);

    if (($claims->type ?? '') !== 'refresh') {
        Response::unprocessable('Provided token is not a refresh token');
    }

    if (($claims->aud ?? '') !== 'nms-refresh') {
        Response::unprocessable('Invalid refresh token audience');
    }

    // Check it hasn't been revoked
    $jti    = $claims->jti ?? '';
    $userId = $claims->sub ?? '';
    $iat    = $claims->iat ?? 0;

    if (!$blocklist->isTokenValid($jti, $userId, $iat)) {
        Response::unauthorized('Refresh token has been revoked');
    }

    // Immediately revoke the old refresh token (one-time use)
    $ttl = ($claims->exp ?? time()) - time();
    $blocklist->revoke($jti, $ttl);

    // Issue new token pair
    $user = [
        'id'          => $userId,
        'roles'       => (array)($claims->roles ?? []),
        'permissions' => (array)($claims->permissions ?? []),
    ];

    $newAccessToken  = $jwt->generateAccessToken($user);
    $newRefreshToken = $jwt->generateRefreshToken($user);

    $appConfig = require dirname(__DIR__, 3) . '/core/config/app.php';

    Response::json([
        'access_token'  => $newAccessToken,
        'refresh_token' => $newRefreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => $appConfig['jwt']['expiry'] ?? 900,
    ]);

} catch (Response) {
    // Already sent
} catch (\RuntimeException $e) {
    Response::unauthorized($e->getMessage());
} catch (\Exception $e) {
    Response::error('Token refresh failed', 500);
}
