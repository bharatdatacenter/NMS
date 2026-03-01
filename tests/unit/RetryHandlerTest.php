<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Helpers\RetryHandler;
use NMS\Core\Helpers\RetryableException;

class RetryHandlerTest extends TestCase
{
    private RetryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        // Use 0ms delays for fast unit tests
        $this->handler = new RetryHandler(maxRetries: 3, baseDelayMs: 0, maxDelayMs: 0);
    }

    public function testSuccessfulCallReturnsValue(): void
    {
        $result = $this->handler->withRetry(fn() => 'success');
        $this->assertEquals('success', $result);
    }

    public function testRetriesOnRetryableException(): void
    {
        $attempts = 0;
        $result = $this->handler->withRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new RetryableException('Transient error', 503);
            }
            return 'recovered';
        });

        $this->assertEquals('recovered', $result);
        $this->assertEquals(3, $attempts);
    }

    public function testDoesNotRetryOn4xxError(): void
    {
        $attempts = 0;
        try {
            $this->handler->withRetry(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('Bad request', 400);
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals(400, $e->getCode());
        }

        // Should have only tried once — 4xx is not retryable
        $this->assertEquals(1, $attempts);
    }

    public function testRetriesOn5xxError(): void
    {
        $attempts = 0;
        try {
            $this->handler->withRetry(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('Server error', 500);
            });
        } catch (\RuntimeException) {}

        $this->assertEquals(4, $attempts); // initial + 3 retries
    }

    public function testRetriesOn429RateLimit(): void
    {
        $attempts = 0;
        try {
            $this->handler->withRetry(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('Rate limited', 429);
            });
        } catch (\RuntimeException) {}

        $this->assertEquals(4, $attempts);
    }

    public function testRetriesOnTimeoutError(): void
    {
        $attempts = 0;
        try {
            $this->handler->withRetry(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('cURL error 28: Operation timed out after 5000', 0);
            });
        } catch (\RuntimeException) {}

        $this->assertEquals(4, $attempts);
    }

    public function testExhaustsRetriesAndThrowsLastException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Final error');

        $this->handler->withRetry(function () {
            throw new RetryableException('Final error', 503);
        });
    }

    public function testBackoffCalculation(): void
    {
        $handler = new RetryHandler(maxRetries: 3, baseDelayMs: 2000, maxDelayMs: 30000);

        // Attempt 0: ~2000ms
        $delay0 = $handler->calculateDelay(0);
        $this->assertGreaterThanOrEqual(2000, $delay0);
        $this->assertLessThanOrEqual(2600, $delay0); // base + max 30% jitter

        // Attempt 1: ~4000ms
        $delay1 = $handler->calculateDelay(1);
        $this->assertGreaterThanOrEqual(4000, $delay1);
        $this->assertLessThanOrEqual(5200, $delay1);

        // Should cap at maxDelay
        $delay10 = $handler->calculateDelay(10);
        $this->assertLessThanOrEqual(30000, $delay10);
    }

    public function testMaxRetriesOverride(): void
    {
        $attempts = 0;
        try {
            $this->handler->withRetry(function () use (&$attempts) {
                $attempts++;
                throw new RetryableException('Error', 503);
            }, maxRetries: 1);
        } catch (\RuntimeException) {}

        $this->assertEquals(2, $attempts); // initial + 1 retry
    }
}
