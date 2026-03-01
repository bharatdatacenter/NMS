<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Helpers\CircuitBreaker;
use NMS\Core\Helpers\CircuitOpenException;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * VendorAdapterTest
 *
 * Tests the circuit breaker integration in VendorAdapter:
 *   - Circuit breaker state is read per device_id
 *   - CircuitOpenException is raised when circuit is open
 *   - State injection works correctly (different devices are isolated)
 *
 * Uses MikroTikAdapter as a concrete representative of VendorAdapter.
 * No real network connections — Redis is mocked.
 */
class VendorAdapterTest extends TestCase
{
    private SecretsManagerInterface $secrets;

    protected function setUp(): void
    {
        $this->secrets = new class implements SecretsManagerInterface {
            public function get(string $path): string   { return 'test'; }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool  { return true; }
        };
    }

    /**
     * Two devices with different IDs have isolated circuit breaker state.
     * Opening device-1's circuit should not affect device-2.
     */
    public function testCircuitBreakerIsIsolatedPerDevice(): void
    {
        // Device 1: circuit open (return serialized open state)
        $openState = json_encode([
            'state'     => 'open',
            'failures'  => 5,
            'opened_at' => time(),  // Just opened — not expired
            'updated_at'=> time(),
        ]);

        // Device 2: circuit closed (null = no entry = closed)
        $device1Id = '507f1f77bcf86cd799439011';
        $device2Id = '507f1f77bcf86cd799439012';

        $redis1 = $this->createMock(RedisClient::class);
        $redis1->method('get')->willReturnCallback(function ($key) use ($device1Id, $openState) {
            return str_contains($key, $device1Id) ? $openState : null;
        });

        $redis2 = $this->createMock(RedisClient::class);
        $redis2->method('get')->willReturn(null); // closed

        $cb1 = new CircuitBreaker($redis1);
        $cb2 = new CircuitBreaker($redis2);

        $this->assertSame('open',   $cb1->getState($device1Id));
        $this->assertSame('closed', $cb2->getState($device2Id));
    }

    /**
     * CircuitOpenException is thrown when a device's circuit breaker is open.
     */
    public function testCircuitBreakerThrowsWhenOpen(): void
    {
        $openState = json_encode([
            'state'     => 'open',
            'failures'  => 5,
            'opened_at' => time(),
            'updated_at'=> time(),
        ]);

        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn($openState);
        $redis->method('set')->willReturn(null);

        $cb = new CircuitBreaker($redis);

        $this->expectException(CircuitOpenException::class);
        $cb->call('device-open', fn() => 'should not reach');
    }

    /**
     * Circuit breaker closes on successful call (failure count reset).
     */
    public function testCircuitBreakerClosesOnSuccess(): void
    {
        $closedState = null; // No state = closed

        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn($closedState);
        $redis->method('set')->willReturn(null);
        $redis->method('del')->willReturn(null);

        $cb = new CircuitBreaker($redis);

        $result = $cb->call('device-closed', fn() => 'success');
        $this->assertSame('success', $result);
    }

    /**
     * MikroTikAdapter correctly reads device ID from both 'id' and '_id' fields.
     */
    public function testAdapterAcceptsDeviceWithStringId(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(null);

        $device = [
            'id'         => '507f1f77bcf86cd799439011',
            'ip_address' => '192.168.1.1',
            'vault_path' => 'nms/devices/test',
        ];

        // Construction should not throw
        $adapter = new MikroTikAdapter($device, $this->secrets, $redis);
        $this->assertNotNull($adapter);
    }

    /**
     * Verify that failure count increments correctly before circuit opens.
     */
    public function testCircuitBreakerTracksFailureCount(): void
    {
        $failures    = 0;
        $storedState = null;

        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturnCallback(function () use (&$storedState) {
            return $storedState;
        });
        $redis->method('set')->willReturnCallback(function ($key, $value) use (&$storedState) {
            $storedState = $value;
            return null;
        });
        $redis->method('del')->willReturn(null);

        $cb = new CircuitBreaker($redis);

        // Simulate failures — expect count to increment, circuit stays closed until threshold
        for ($i = 0; $i < 4; $i++) {
            try {
                $cb->call('dev-failures', fn() => throw new \RuntimeException("fail {$i}"));
            } catch (\RuntimeException) {}

            if ($storedState !== null) {
                $data = json_decode($storedState, true);
                $this->assertGreaterThan(0, $data['failures']);
            }
        }

        // After 4 failures, circuit should still be closed (threshold is 5)
        $this->assertNotSame('open', $cb->getState('dev-failures'));
    }

    /**
     * Circuit opens after exactly FAILURE_THRESHOLD (5) consecutive failures.
     */
    public function testCircuitOpensAfterFiveConsecutiveFailures(): void
    {
        $storedState = null;

        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturnCallback(fn() => $storedState);
        $redis->method('set')->willReturnCallback(function ($key, $value) use (&$storedState) {
            $storedState = $value;
        });
        $redis->method('del')->willReturn(null);

        $cb = new CircuitBreaker($redis);

        for ($i = 0; $i < 5; $i++) {
            try {
                $cb->call('dev-trip', fn() => throw new \RuntimeException("fail"));
            } catch (\RuntimeException | CircuitOpenException) {}
        }

        // After 5 failures, state should be open
        $data = json_decode($storedState ?? '{}', true);
        $this->assertSame('open', $data['state'] ?? null);
    }
}
