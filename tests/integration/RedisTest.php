<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Auth\TokenBlocklist;
use Predis\Client as RedisClient;

/**
 * @group integration
 * Requires a running Redis instance.
 */
class RedisTest extends TestCase
{
    private RedisClient $redis;
    private TokenBlocklist $blocklist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis     = self::getRedis();
        $this->blocklist = new TokenBlocklist($this->redis);
        $this->flushRedis();
    }

    public function testRedisConnection(): void
    {
        $response = $this->redis->ping();
        $this->assertEquals('PONG', $response->getPayload());
    }

    public function testSetAndGet(): void
    {
        $this->redis->set('nms:test:key', 'hello');
        $this->assertEquals('hello', $this->redis->get('nms:test:key'));
    }

    public function testSetWithTTL(): void
    {
        $this->redis->set('nms:test:ttl', 'value', 'EX', 10);
        $this->assertGreaterThan(0, $this->redis->ttl('nms:test:ttl'));
        $this->assertLessThanOrEqual(10, $this->redis->ttl('nms:test:ttl'));
    }

    public function testBlocklistRevokeAndCheck(): void
    {
        $jti = 'integration-jti-' . uniqid();

        $this->assertFalse($this->blocklist->isRevoked($jti));

        $this->blocklist->revoke($jti, 300);
        $this->assertTrue($this->blocklist->isRevoked($jti));
    }

    public function testBlocklistBulkRevocation(): void
    {
        $userId = 'user-integration-' . uniqid();

        $jti = 'jti-old-' . uniqid();
        $oldIat = time() - 60;

        $this->blocklist->revokeAllForUser($userId);

        // Old token should fail bulk check
        $this->assertFalse($this->blocklist->isIssuedAfterBulkRevocation($userId, $oldIat));

        // New token should pass
        $newIat = time() + 1;
        $this->assertTrue($this->blocklist->isIssuedAfterBulkRevocation($userId, $newIat));
    }

    public function testSlidingWindowRateLimit(): void
    {
        $key = 'ratelimit:test:' . uniqid();
        $window = 60;
        $now = time();

        // Add 5 requests
        for ($i = 0; $i < 5; $i++) {
            $this->redis->zremrangebyscore($key, 0, $now - $window);
            $this->redis->zadd($key, [$now . ':' . $i => $now]);
            $this->redis->expire($key, $window + 1);
        }

        $count = $this->redis->zcard($key);
        $this->assertEquals(5, $count);
    }
}
