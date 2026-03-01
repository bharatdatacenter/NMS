<?php

declare(strict_types=1);

namespace NMS\Api\Middleware;

use NMS\Core\Helpers\Response;
use Predis\Client as RedisClient;

/**
 * RateLimitMiddleware — Redis sliding window rate limiter.
 * Default: 100 requests/minute per user (by IP if unauthenticated).
 */
class RateLimitMiddleware
{
    private static ?RedisClient $redis = null;

    public static function handle(array $request): void
    {
        $config = require dirname(__DIR__, 2) . '/core/config/app.php';
        $limit  = $config['rate_limit']['requests'] ?? 100;
        $window = $config['rate_limit']['window']   ?? 60;

        // Key by IP address; if JWT user is available, key by user ID
        $identifier = self::getIdentifier($request);
        $key        = 'ratelimit:' . $identifier;
        $now        = time();

        $redis = self::getRedis();

        // Sliding window: ZADD current timestamp, ZREMRANGEBYSCORE old entries, ZCARD
        $redis->zremrangebyscore($key, 0, $now - $window);
        $redis->zadd($key, [$now . ':' . random_int(0, PHP_INT_MAX) => $now]);
        $redis->expire($key, $window + 1);
        $count = $redis->zcard($key);

        if ($count > $limit) {
            header('Retry-After: ' . $window);
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: 0');
            Response::tooManyRequests('Rate limit exceeded. Try again in ' . $window . ' seconds.');
        }

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $count));
    }

    private static function getIdentifier(array $request): string
    {
        // Try to get authenticated user ID from bearer token claims
        $authHeader = $request['headers']['Authorization']
            ?? $request['headers']['authorization']
            ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            try {
                $token  = substr($authHeader, 7);
                $parts  = explode('.', $token);
                $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
                if (isset($claims['sub'])) {
                    return 'user:' . $claims['sub'];
                }
            } catch (\Exception) {}
        }
        return 'ip:' . ($request['ip'] ?? '0.0.0.0');
    }

    private static function getRedis(): RedisClient
    {
        if (self::$redis === null) {
            $config = require dirname(__DIR__, 2) . '/core/config/redis.php';
            self::$redis = new RedisClient($config);
        }
        return self::$redis;
    }
}
