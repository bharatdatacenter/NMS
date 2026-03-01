<?php

declare(strict_types=1);

namespace NMS\Api\Middleware;

use NMS\Core\Helpers\Response;
use Predis\Client as RedisClient;

/**
 * IdempotencyMiddleware — X-Idempotency-Key header deduplication.
 *
 * On first request: execute normally, cache response for 24h.
 * On duplicate request with same key: return cached response immediately.
 * Prevents double-provisioning, double-release, etc.
 */
class IdempotencyMiddleware
{
    private const TTL = 86400; // 24 hours
    private const KEY_PREFIX = 'idempotency:';

    private static ?RedisClient $redis = null;
    private static ?string $currentKey = null;

    /**
     * Check for an existing cached response.
     * Returns cached response array if key exists, null if first request.
     */
    public static function handle(array $request): ?array
    {
        $key = $request['headers']['X-Idempotency-Key']
            ?? $request['headers']['x-idempotency-key']
            ?? null;

        if ($key === null) {
            Response::unprocessable('X-Idempotency-Key header is required for this endpoint');
        }

        if (strlen($key) > 128) {
            Response::unprocessable('X-Idempotency-Key must not exceed 128 characters');
        }

        self::$currentKey = $key;
        $redisKey = self::KEY_PREFIX . hash('sha256', $key);
        $redis    = self::getRedis();

        $cached = $redis->get($redisKey);
        if ($cached !== null) {
            header('X-Idempotency-Replay: true');
            return json_decode($cached, true);
        }

        return null;
    }

    /**
     * Store a response for the current idempotency key.
     * Call this after a successful handler execution.
     */
    public static function store(array $response): void
    {
        if (self::$currentKey === null) {
            return;
        }
        $redisKey = self::KEY_PREFIX . hash('sha256', self::$currentKey);
        $redis    = self::getRedis();
        $redis->set($redisKey, json_encode($response), 'EX', self::TTL);
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
