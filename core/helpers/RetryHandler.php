<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

use RuntimeException;

/**
 * Exponential backoff with jitter for vendor API calls.
 *
 * Behavior:
 *   - Retries on: timeout, 5xx errors, 429 (rate limit)
 *   - Does NOT retry: 4xx errors (except 429), invalid responses
 *   - Base delay: 2s, max delay: 30s, with random jitter
 *   - Max retries: 3 (configurable)
 */
class RetryHandler
{
    private int $maxRetries;
    private int $baseDelayMs;
    private int $maxDelayMs;

    public function __construct(int $maxRetries = 3, int $baseDelayMs = 2000, int $maxDelayMs = 30000)
    {
        $this->maxRetries  = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
        $this->maxDelayMs  = $maxDelayMs;
    }

    /**
     * Execute a callable with automatic retry on transient failures.
     *
     * @param  callable $fn         Function to execute. Should throw RetryableException for retryable failures.
     * @param  int|null $maxRetries Override max retries for this call
     * @return mixed                Return value of $fn
     * @throws \Exception           Last exception after all retries exhausted, or non-retryable exception
     */
    public function withRetry(callable $fn, ?int $maxRetries = null): mixed
    {
        $maxRetries ??= $this->maxRetries;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $fn($attempt);
            } catch (\Exception $e) {
                if (!$this->isRetryable($e)) {
                    throw $e;
                }
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    $delayMs = $this->calculateDelay($attempt);
                    usleep($delayMs * 1000);
                }
                $attempt++;
            }
        }

        throw $lastException ?? new RuntimeException('Retry handler failed with no exception');
    }

    /**
     * Calculate exponential backoff delay in milliseconds with jitter.
     * delay = min(baseDelay * 2^attempt + jitter, maxDelay)
     */
    public function calculateDelay(int $attempt): int
    {
        $exponential = $this->baseDelayMs * (2 ** $attempt);
        $jitter      = random_int(0, (int)($exponential * 0.3));
        return min($exponential + $jitter, $this->maxDelayMs);
    }

    /**
     * Determine if an exception is retryable.
     * Override to customize retry logic.
     */
    protected function isRetryable(\Exception $e): bool
    {
        if ($e instanceof RetryableException) {
            return true;
        }

        $code = $e->getCode();

        // 429 (rate limit) and 5xx → retryable
        if ($code === 429 || ($code >= 500 && $code < 600)) {
            return true;
        }

        // 4xx other than 429 → not retryable
        if ($code >= 400 && $code < 500) {
            return false;
        }

        // Curl errors (connection refused, timeout) — code 0 or negative or CURLE_* codes
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'connection refused')
            || str_contains($message, 'connection reset') || str_contains($message, 'curl error')) {
            return true;
        }

        return false;
    }
}

/**
 * Exception type to signal retryable failures explicitly.
 */
class RetryableException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
