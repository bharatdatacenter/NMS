<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Helpers\CircuitBreaker;
use NMS\Core\Helpers\CircuitOpenException;
use NMS\Core\Helpers\RetryHandler;
use NMS\Core\Helpers\RetryableException;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * RetryCircuitBreakerTest
 *
 * Integration tests for the RetryHandler + CircuitBreaker combination
 * as used in VendorAdapter.
 *
 * Tests:
 *   - Adapter retries on transient failures (RetryableException)
 *   - Circuit breaker opens after 5 consecutive failures
 *   - Circuit breaker enters half_open after cooldown
 *   - Successful call after half_open closes the circuit
 *
 * Uses Redis (requires running Redis). If Redis is unavailable, tests are skipped.
 */
class RetryCircuitBreakerTest extends TestCase
{
    private RedisClient $redis;
    private string $deviceId = 'test-device-retry-cb';

    protected function setUp(): void
    {
        try {
            $config      = require dirname(__DIR__, 2) . '/core/config/redis.php';
            $this->redis = new RedisClient($config);
            $this->redis->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis unavailable: ' . $e->getMessage());
        }

        // Clean slate for each test
        $this->redis->del("cb:{$this->deviceId}");
    }

    protected function tearDown(): void
    {
        try {
            $this->redis->del("cb:{$this->deviceId}");
        } catch (\Exception) {}
    }

    // ─── RetryHandler ─────────────────────────────────────────────────────────

    public function testRetryHandlerRetriesOnRetryableException(): void
    {
        $handler  = new RetryHandler(3, 0, 0); // 0ms delays for fast tests
        $attempts = 0;

        $result = $handler->withRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new RetryableException('Transient error');
            }
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
    }

    public function testRetryHandlerDoesNotRetryOnNonRetryableException(): void
    {
        $handler  = new RetryHandler(3, 0, 0);
        $attempts = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(404);

        $handler->withRetry(function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Not found', 404); // 4xx = not retryable
        });

        $this->assertSame(1, $attempts, 'Should not retry on 404');
    }

    public function testRetryHandlerExhaustsRetriesAndThrows(): void
    {
        $handler  = new RetryHandler(2, 0, 0); // max 2 retries
        $attempts = 0;

        $this->expectException(RetryableException::class);

        $handler->withRetry(function () use (&$attempts) {
            $attempts++;
            throw new RetryableException('Always fails');
        });

        $this->assertSame(3, $attempts); // 1 initial + 2 retries
    }

    // ─── CircuitBreaker ───────────────────────────────────────────────────────

    public function testCircuitBreakerOpenAfterFiveFailures(): void
    {
        $cb = new CircuitBreaker($this->redis);

        for ($i = 0; $i < 5; $i++) {
            try {
                $cb->call($this->deviceId, fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException | CircuitOpenException) {}
        }

        $this->assertSame('open', $cb->getState($this->deviceId));
    }

    public function testCircuitBreakerRejectsFastWhenOpen(): void
    {
        $cb = new CircuitBreaker($this->redis);

        // Force open
        for ($i = 0; $i < 5; $i++) {
            try {
                $cb->call($this->deviceId, fn() => throw new \RuntimeException('fail'));
            } catch (\Exception) {}
        }

        $this->assertSame('open', $cb->getState($this->deviceId));

        // Next call should fail fast with CircuitOpenException (no actual call)
        $wasCalled = false;
        $this->expectException(CircuitOpenException::class);
        $cb->call($this->deviceId, function () use (&$wasCalled) {
            $wasCalled = true;
            return 'should not reach';
        });
    }

    public function testCircuitBreakerClosesOnSuccessInHalfOpen(): void
    {
        $cb = new CircuitBreaker($this->redis);

        // Manually inject half_open state (simulates cooldown expiry)
        $halfOpenState = json_encode([
            'state'      => 'half_open',
            'failures'   => 5,
            'opened_at'  => time() - 61, // 61s ago — past cooldown
            'updated_at' => time(),
        ]);
        $this->redis->set("cb:{$this->deviceId}", $halfOpenState, 'EX', 600);

        // Successful call in half_open should close the circuit
        $result = $cb->call($this->deviceId, fn() => 'recovered');
        $this->assertSame('recovered', $result);
        $this->assertSame('closed', $cb->getState($this->deviceId));
    }

    public function testCircuitBreakerManualReset(): void
    {
        $cb = new CircuitBreaker($this->redis);

        // Trip open
        for ($i = 0; $i < 5; $i++) {
            try {
                $cb->call($this->deviceId, fn() => throw new \RuntimeException('fail'));
            } catch (\Exception) {}
        }
        $this->assertSame('open', $cb->getState($this->deviceId));

        // Manual reset
        $cb->reset($this->deviceId);
        $this->assertSame('closed', $cb->getState($this->deviceId));
    }

    public function testCircuitBreakerFailureCountIncrements(): void
    {
        $cb = new CircuitBreaker($this->redis);

        for ($i = 1; $i <= 3; $i++) {
            try {
                $cb->call($this->deviceId, fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {}
        }

        $count = $cb->getFailureCount($this->deviceId);
        $this->assertSame(3, $count);
    }

    // ─── Combined RetryHandler + CircuitBreaker ───────────────────────────────

    /**
     * Simulate what VendorAdapter.call() does:
     * CircuitBreaker wraps RetryHandler which wraps the API call.
     * After 5 adapter-level failures, circuit opens.
     */
    public function testAdapterRetryThenCircuitBreaker(): void
    {
        $cb      = new CircuitBreaker($this->redis);
        $handler = new RetryHandler(1, 0, 0); // 1 retry per CB attempt

        $attempts = 0;

        // Make 3 CB calls, each with 1 retry = 6 total API attempts (but CB opens at 5)
        for ($cbAttempt = 0; $cbAttempt < 6; $cbAttempt++) {
            try {
                $cb->call($this->deviceId, function () use (&$attempts, $handler): void {
                    $handler->withRetry(function () use (&$attempts): void {
                        $attempts++;
                        throw new RetryableException('Device timeout');
                    });
                });
            } catch (CircuitOpenException) {
                // Circuit opened — stop
                break;
            } catch (\Exception) {
                // CB failure recorded
            }
        }

        // Circuit should be open after enough failures
        $this->assertSame('open', $cb->getState($this->deviceId));
    }
}
