<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Core\Helpers\RetryHandler;
use NMS\Core\Models\Notifications\ChannelInterface;
use NMS\Core\Models\Notifications\NotificationManager;
use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Notifications\RuleResolver;
use PHPUnit\Framework\TestCase;

/**
 * NotificationDispatchTest
 *
 * Exercises the full dispatch path with all collaborators injected — no real
 * Mongo, Redis, or network. Verifies per-target success/failure accounting and
 * that disabled channels are skipped (not failed).
 *
 * Note: assertions target the dispatch summary (the contract), not the
 * notification_log write. Log persistence uses MongoDB\BSON\UTCDateTime, which
 * requires the mongodb C extension; that write is best-effort and its failure
 * is intentionally swallowed so logging can never break delivery.
 */
class NotificationDispatchTest extends TestCase
{
    private function resolverReturning(array $targets): RuleResolver
    {
        return new class($targets) extends RuleResolver {
            public function __construct(private array $fixture) {}
            public function resolve(NotificationMessage $message): array
            {
                return $this->fixture;
            }
        };
    }

    private function channel(string $name, bool $enabled, ?\Throwable $throw = null, string $providerId = 'ok'): ChannelInterface
    {
        return new class($name, $enabled, $throw, $providerId) implements ChannelInterface {
            public int $sendCalls = 0;
            public function __construct(
                private string $n,
                private bool $enabled,
                private ?\Throwable $throw,
                private string $providerId
            ) {}
            public function name(): string { return $this->n; }
            public function isEnabled(): bool { return $this->enabled; }
            public function send(NotificationMessage $message, string $target): string
            {
                $this->sendCalls++;
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                return $this->providerId;
            }
        };
    }

    private function message(): NotificationMessage
    {
        return new NotificationMessage('drift_detected', 3, 'Drift', 'Body', ['device_id' => 'd1']);
    }

    public function testDispatchDeliversToAllEnabledTargets(): void
    {
        $resolver = $this->resolverReturning([
            ['channel' => 'email',    'address' => 'a@example.com', 'rule_id' => 'r1', 'rule_name' => 'n1'],
            ['channel' => 'telegram', 'address' => '123',           'rule_id' => 'r1', 'rule_name' => 'n1'],
        ]);

        $log = $this->createMock(\MongoDB\Collection::class);

        $manager = new NotificationManager(
            config: ['enabled' => true],
            resolver: $resolver,
            log: $log,
            channels: [
                'email'    => $this->channel('email', true),
                'telegram' => $this->channel('telegram', true),
            ],
            retry: new RetryHandler(0, 1, 1),
            breaker: null,
        );

        $summary = $manager->dispatch($this->message());

        $this->assertSame(2, $summary['sent']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(2, $summary['targets']);
    }

    public function testDispatchCountsFailuresAndContinues(): void
    {
        $resolver = $this->resolverReturning([
            ['channel' => 'telegram', 'address' => '123', 'rule_id' => 'r1', 'rule_name' => 'n1'],
        ]);

        $log = $this->createMock(\MongoDB\Collection::class);

        $manager = new NotificationManager(
            config: ['enabled' => true],
            resolver: $resolver,
            log: $log,
            channels: [
                'telegram' => $this->channel('telegram', true, new \RuntimeException('boom', 400)),
            ],
            retry: new RetryHandler(0, 1, 1),
            breaker: null,
        );

        $summary = $manager->dispatch($this->message());

        $this->assertSame(0, $summary['sent']);
        $this->assertSame(1, $summary['failed']);
    }

    public function testDispatchSkipsDisabledChannel(): void
    {
        $resolver = $this->resolverReturning([
            ['channel' => 'email', 'address' => 'a@example.com', 'rule_id' => 'r1', 'rule_name' => 'n1'],
        ]);

        $log = $this->createMock(\MongoDB\Collection::class);

        $disabled = $this->channel('email', false);

        $manager = new NotificationManager(
            config: ['enabled' => true],
            resolver: $resolver,
            log: $log,
            channels: ['email' => $disabled],
            retry: new RetryHandler(0, 1, 1),
            breaker: null,
        );

        $summary = $manager->dispatch($this->message());

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['sent']);
        $this->assertSame(0, $disabled->sendCalls, 'Disabled channel must not be invoked');
    }

    public function testDispatchIsNoOpWhenGloballyDisabled(): void
    {
        $resolver = $this->resolverReturning([
            ['channel' => 'email', 'address' => 'a@example.com', 'rule_id' => 'r1', 'rule_name' => 'n1'],
        ]);

        $log = $this->createMock(\MongoDB\Collection::class);
        $log->expects($this->never())->method('insertOne');

        $manager = new NotificationManager(
            config: ['enabled' => false],
            resolver: $resolver,
            log: $log,
            channels: ['email' => $this->channel('email', true)],
            retry: new RetryHandler(0, 1, 1),
            breaker: null,
        );

        $summary = $manager->dispatch($this->message());

        $this->assertSame(0, $summary['targets']);
        $this->assertSame(0, $summary['sent']);
    }
}
