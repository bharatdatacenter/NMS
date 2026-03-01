<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Helpers\CircuitBreaker;
use NMS\Core\Helpers\CircuitOpenException;

class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $cb;
    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushRedis();
        $this->cb       = new CircuitBreaker(self::getRedis());
        $this->deviceId = 'test-device-' . uniqid();
    }

    public function testInitialStateIsClosed(): void
    {
        $this->assertEquals('closed', $this->cb->getState($this->deviceId));
    }

    public function testSuccessfulCallReturnsValue(): void
    {
        $result = $this->cb->call($this->deviceId, fn() => 'ok');
        $this->assertEquals('ok', $result);
    }

    public function testCircuitOpensAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->cb->call($this->deviceId, function () {
                    throw new \RuntimeException('Device unreachable', 503);
                });
            } catch (CircuitOpenException) {
                break;
            } catch (\RuntimeException) {
                // Expected on calls 1-5
            }
        }

        $this->assertEquals('open', $this->cb->getState($this->deviceId));
    }

    public function testOpenCircuitRejectsFastWithoutCallingFn(): void
    {
        // Force circuit open
        $this->cb->forceOpen($this->deviceId);

        $called = false;
        try {
            $this->cb->call($this->deviceId, function () use (&$called) {
                $called = true;
            });
        } catch (CircuitOpenException) {}

        $this->assertFalse($called, 'Function should not be called when circuit is open');
    }

    public function testCircuitResetsOnSuccess(): void
    {
        // Cause 4 failures
        for ($i = 0; $i < 4; $i++) {
            try {
                $this->cb->call($this->deviceId, function () {
                    throw new \RuntimeException('Error', 503);
                });
            } catch (\RuntimeException) {}
        }

        // Success resets the circuit
        $this->cb->call($this->deviceId, fn() => true);
        $this->assertEquals(0, $this->cb->getFailureCount($this->deviceId));
    }

    public function testManualReset(): void
    {
        $this->cb->forceOpen($this->deviceId);
        $this->assertEquals('open', $this->cb->getState($this->deviceId));

        $this->cb->reset($this->deviceId);
        $this->assertEquals('closed', $this->cb->getState($this->deviceId));
    }

    public function testDifferentDevicesHaveIndependentCircuits(): void
    {
        $device1 = 'device-a-' . uniqid();
        $device2 = 'device-b-' . uniqid();

        $this->cb->forceOpen($device1);

        $this->assertEquals('open', $this->cb->getState($device1));
        $this->assertEquals('closed', $this->cb->getState($device2));
    }

    public function testCircuitBreakerExceptionNotRethrownAsGeneric(): void
    {
        $this->cb->forceOpen($this->deviceId);

        try {
            $this->cb->call($this->deviceId, fn() => null);
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            $this->assertStringContainsString($this->deviceId, $e->getMessage());
        }
    }

    public function testHalfOpenAfterCooldown(): void
    {
        // Open the circuit with a past timestamp to simulate cooldown passed
        $redis    = self::getRedis();
        $cbKey    = 'cb:' . $this->deviceId;
        $pastTime = time() - 61; // More than 60s ago
        $redis->set($cbKey, json_encode([
            'state'     => 'open',
            'failures'  => 5,
            'opened_at' => $pastTime,
            'updated_at'=> $pastTime,
        ]), 'EX', 600);

        $state = $this->cb->getState($this->deviceId);
        $this->assertEquals('half_open', $state);
    }
}
