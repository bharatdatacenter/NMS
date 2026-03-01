<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

use Predis\Client as RedisClient;
use RuntimeException;

/**
 * Redis-backed per-device circuit breaker.
 *
 * States:
 *   closed    — normal operation, calls go through
 *   open      — circuit tripped, calls fail fast (60s cooldown)
 *   half_open — probe state after cooldown, single call allowed
 *
 * Trips open after 5 consecutive failures.
 * After 60s, enters half_open to probe recovery.
 */
class CircuitBreaker
{
    private const STATE_CLOSED    = 'closed';
    private const STATE_OPEN      = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private const FAILURE_THRESHOLD = 5;
    private const COOLDOWN_SECONDS  = 60;
    private const KEY_PREFIX        = 'cb:';

    private RedisClient $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Execute a callable through the circuit breaker.
     *
     * @param  string   $deviceId  Unique device identifier (used as circuit key)
     * @param  callable $fn        The API call to execute
     * @return mixed               Return value of $fn on success
     * @throws CircuitOpenException if circuit is open
     * @throws \Exception           if $fn throws and the circuit is not yet open
     */
    public function call(string $deviceId, callable $fn): mixed
    {
        $state = $this->getState($deviceId);

        if ($state === self::STATE_OPEN) {
            throw new CircuitOpenException(
                "Circuit breaker is OPEN for device {$deviceId}. Retry after cooldown."
            );
        }

        try {
            $result = $fn();
            $this->onSuccess($deviceId);
            return $result;
        } catch (CircuitOpenException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->onFailure($deviceId);
            throw $e;
        }
    }

    /**
     * Get the current state of a device's circuit breaker.
     */
    public function getState(string $deviceId): string
    {
        $data = $this->getData($deviceId);

        if ($data === null || $data['state'] === self::STATE_CLOSED) {
            return self::STATE_CLOSED;
        }

        if ($data['state'] === self::STATE_OPEN) {
            // Check if cooldown has expired
            if (time() - $data['opened_at'] >= self::COOLDOWN_SECONDS) {
                $this->setState($deviceId, self::STATE_HALF_OPEN);
                return self::STATE_HALF_OPEN;
            }
            return self::STATE_OPEN;
        }

        return $data['state'];
    }

    /**
     * Get failure count for a device.
     */
    public function getFailureCount(string $deviceId): int
    {
        return (int)($this->getData($deviceId)['failures'] ?? 0);
    }

    /**
     * Manually reset a circuit breaker (admin action).
     */
    public function reset(string $deviceId): void
    {
        $this->redis->del(self::KEY_PREFIX . $deviceId);
    }

    /**
     * Force a circuit open (e.g., device marked offline by admin).
     */
    public function forceOpen(string $deviceId): void
    {
        $this->setState($deviceId, self::STATE_OPEN, self::FAILURE_THRESHOLD);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function onSuccess(string $deviceId): void
    {
        $data = $this->getData($deviceId);
        $state = $data['state'] ?? self::STATE_CLOSED;

        if ($state === self::STATE_HALF_OPEN || $state === self::STATE_OPEN) {
            // Recovery — close the circuit
            $this->redis->del(self::KEY_PREFIX . $deviceId);
        } elseif (($data['failures'] ?? 0) > 0) {
            // Reset failure count on success in closed state
            $this->redis->del(self::KEY_PREFIX . $deviceId);
        }
    }

    private function onFailure(string $deviceId): void
    {
        $data     = $this->getData($deviceId) ?? ['state' => self::STATE_CLOSED, 'failures' => 0];
        $failures = ($data['failures'] ?? 0) + 1;

        if ($failures >= self::FAILURE_THRESHOLD || $data['state'] === self::STATE_HALF_OPEN) {
            // Trip the circuit open
            $this->setState($deviceId, self::STATE_OPEN, $failures);
        } else {
            // Increment failure count, stay closed
            $data['failures'] = $failures;
            $this->saveData($deviceId, $data);
        }
    }

    private function setState(string $deviceId, string $state, int $failures = 0): void
    {
        $data = [
            'state'     => $state,
            'failures'  => $failures,
            'opened_at' => $state === self::STATE_OPEN ? time() : 0,
            'updated_at'=> time(),
        ];
        $this->saveData($deviceId, $data);
    }

    private function getData(string $deviceId): ?array
    {
        $raw = $this->redis->get(self::KEY_PREFIX . $deviceId);
        if ($raw === null) {
            return null;
        }
        return json_decode($raw, true) ?? null;
    }

    private function saveData(string $deviceId, array $data): void
    {
        // TTL: 10 minutes (circuit data auto-expires if not updated)
        $this->redis->set(self::KEY_PREFIX . $deviceId, json_encode($data), 'EX', 600);
    }
}

/**
 * Thrown when a circuit breaker is open and the call is rejected.
 */
class CircuitOpenException extends RuntimeException {}
