<?php

declare(strict_types=1);

namespace NMS\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Logger;
use Predis\Client as RedisClient;

/**
 * Base test case for all NMS tests.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected static ?RedisClient $redis = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Load .env.example as test config base
        $this->loadTestEnv();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        MongoDB::reset();
        Logger::reset();
    }

    protected static function getRedis(): RedisClient
    {
        if (self::$redis === null) {
            self::$redis = new RedisClient([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
                'database' => (int)(getenv('REDIS_DB') ?: 15), // test DB
            ]);
        }
        return self::$redis;
    }

    protected function flushRedis(): void
    {
        self::getRedis()->flushdb();
    }

    private function loadTestEnv(): void
    {
        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            $envFile = dirname(__DIR__) . '/.env.example';
        }
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                if (!array_key_exists($key, $_ENV)) {
                    putenv("$key=" . trim($value));
                    $_ENV[$key] = trim($value);
                }
            }
        }
        // Always use test database
        putenv('MONGODB_DATABASE=nms_test');
        putenv('REDIS_DB=15');
    }
}
