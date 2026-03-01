<?php

declare(strict_types=1);

namespace NMS\Api\Middleware;

use NMS\Core\Auth\JWTHelper;
use NMS\Core\Auth\TokenBlocklist;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use Predis\Client as RedisClient;
use stdClass;

/**
 * AuthMiddleware — validates JWT, checks Redis blocklist, attaches claims to request.
 */
class AuthMiddleware
{
    /**
     * @param  array $request  Request context array
     * @return stdClass        Decoded JWT claims (attached to $request['user'])
     */
    public static function handle(array $request): stdClass
    {
        $authHeader = $request['headers']['Authorization']
            ?? $request['headers']['authorization']
            ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::unauthorized('Authorization header missing or invalid');
        }

        $token = substr($authHeader, 7);

        try {
            $secrets = new VaultSecretsManager();
            $redis   = static::getRedis();
            $jwt     = new JWTHelper($secrets);
            $blocklist = new TokenBlocklist($redis);

            $claims = $jwt->validate($token);

            // Check individual token revocation
            $jti    = $claims->jti ?? '';
            $userId = $claims->sub ?? '';
            $iat    = $claims->iat ?? 0;

            if (!$blocklist->isTokenValid($jti, $userId, $iat)) {
                Response::unauthorized('Token has been revoked');
            }

            // Reject refresh tokens on API endpoints (they are for /auth/refresh only)
            if (($claims->type ?? '') === 'refresh') {
                Response::unauthorized('Refresh token cannot be used for API access');
            }

        } catch (Response) {
            throw $response ?? new \RuntimeException('Auth error');
        } catch (\RuntimeException $e) {
            Response::unauthorized($e->getMessage());
        } catch (\Exception $e) {
            Response::unauthorized('Token validation failed');
        }

        return $claims;
    }

    private static function getRedis(): RedisClient
    {
        static $redis = null;
        if ($redis === null) {
            $config = require dirname(__DIR__, 2) . '/core/config/redis.php';
            $redis  = new RedisClient($config);
        }
        return $redis;
    }
}
