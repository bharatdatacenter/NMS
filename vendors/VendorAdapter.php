<?php

declare(strict_types=1);

namespace NMS\Vendors;

use MongoDB\BSON\UTCDateTime;
use NMS\Core\Models\Devices\DeviceInterface;
use NMS\Core\Models\Devices\DeviceManager;
use NMS\Core\Helpers\CircuitBreaker;
use NMS\Core\Helpers\CircuitOpenException;
use NMS\Core\Helpers\RetryHandler;
use Predis\Client as RedisClient;

/**
 * VendorAdapter — abstract base for all vendor-specific adapters.
 *
 * Wraps every API call with:
 *   1. CircuitBreaker — fails fast when a device is unreachable (open after 5 failures)
 *   2. RetryHandler   — exponential backoff with jitter on transient failures
 *
 * On circuit breaker state changes, updates device.circuit_breaker in MongoDB
 * so operators can see device reachability at a glance.
 */
abstract class VendorAdapter implements DeviceInterface
{
    protected array $device;
    protected RetryHandler $retry;
    protected CircuitBreaker $cb;
    private DeviceManager $deviceManager;
    private string $deviceId;

    public function __construct(array $device, RedisClient $redis)
    {
        $this->device        = $device;
        $this->deviceId      = (string)($device['id'] ?? $device['_id'] ?? '');
        $this->retry         = new RetryHandler(3, 2000, 30000);
        $this->cb            = new CircuitBreaker($redis);
        $this->deviceManager = new DeviceManager();
    }

    /**
     * Execute an API call wrapped with CircuitBreaker + RetryHandler.
     * Syncs circuit breaker state to MongoDB on state transitions.
     *
     * @param  callable $fn  The API call to execute (no arguments)
     * @return mixed         Return value of $fn
     * @throws CircuitOpenException if circuit is open
     * @throws \Exception           on API failure after retries
     */
    protected function call(callable $fn): mixed
    {
        $prevState = $this->cb->getState($this->deviceId);

        try {
            $result = $this->cb->call(
                $this->deviceId,
                fn() => $this->retry->withRetry(fn() => $fn())
            );

            $newState = $this->cb->getState($this->deviceId);
            if ($prevState !== 'closed' && $newState === 'closed') {
                // Recovery — circuit just closed
                $this->syncCircuitBreakerState('closed', 0);
            }

            return $result;

        } catch (CircuitOpenException $e) {
            $this->syncCircuitBreakerState('open', $this->cb->getFailureCount($this->deviceId));
            throw $e;
        } catch (\Exception $e) {
            $newState = $this->cb->getState($this->deviceId);
            if ($newState !== $prevState) {
                $this->syncCircuitBreakerState($newState, $this->cb->getFailureCount($this->deviceId));
            }
            throw $e;
        }
    }

    /**
     * Update the device document's circuit_breaker field in MongoDB.
     * Best-effort — failures are swallowed to not cascade.
     */
    private function syncCircuitBreakerState(string $state, int $failures): void
    {
        if (empty($this->deviceId)) {
            return;
        }
        try {
            $this->deviceManager->updateCircuitBreaker($this->deviceId, [
                'state'                => $state,
                'consecutive_failures' => $failures,
                'last_failure'         => $state !== 'closed' ? new UTCDateTime() : null,
                'cooldown_until'       => $state === 'open'
                    ? new UTCDateTime((time() + 60) * 1000)
                    : null,
            ]);
        } catch (\Throwable) {
            // Best-effort — do not propagate
        }
    }
}
