<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Notifications\RuleResolver;
use PHPUnit\Framework\TestCase;

/**
 * RuleResolverTest
 *
 * Pure in-process tests of the rule → target resolution and payload
 * normalization logic. The MongoDB\Collection is mocked, so no database is
 * required.
 */
class RuleResolverTest extends TestCase
{
    private function message(int $severity = 3, string $event = 'drift_detected'): NotificationMessage
    {
        return new NotificationMessage($event, $severity, 'Title', 'Body', []);
    }

    public function testResolveExtractsTargetsFromMatchingRules(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $collection->method('find')->willReturn([
            [
                '_id'     => 'rule1',
                'name'    => 'ops-email',
                'targets' => [
                    ['channel' => 'email', 'address' => 'ops@example.com'],
                    ['channel' => 'telegram', 'address' => '12345'],
                ],
            ],
        ]);

        $resolver = new RuleResolver($collection);
        $targets  = $resolver->resolve($this->message());

        $this->assertCount(2, $targets);
        $this->assertSame('email', $targets[0]['channel']);
        $this->assertSame('ops@example.com', $targets[0]['address']);
        $this->assertSame('ops-email', $targets[0]['rule_name']);
    }

    public function testResolveDeduplicatesIdenticalTargetsAcrossRules(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $collection->method('find')->willReturn([
            ['_id' => 'r1', 'name' => 'a', 'targets' => [['channel' => 'email', 'address' => 'dup@example.com']]],
            ['_id' => 'r2', 'name' => 'b', 'targets' => [['channel' => 'email', 'address' => 'dup@example.com']]],
        ]);

        $resolver = new RuleResolver($collection);
        $targets  = $resolver->resolve($this->message());

        $this->assertCount(1, $targets, 'Identical channel+address should be delivered once');
    }

    public function testResolveSkipsMalformedTargets(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $collection->method('find')->willReturn([
            ['_id' => 'r1', 'name' => 'a', 'targets' => [
                ['channel' => '', 'address' => 'x@example.com'],   // no channel
                ['channel' => 'email', 'address' => ''],           // no address
                ['channel' => 'email', 'address' => 'ok@example.com'],
            ]],
        ]);

        $resolver = new RuleResolver($collection);
        $targets  = $resolver->resolve($this->message());

        $this->assertCount(1, $targets);
        $this->assertSame('ok@example.com', $targets[0]['address']);
    }

    public function testCreateRejectsInvalidChannel(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $resolver   = new RuleResolver($collection);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->create([
            'name'       => 'bad',
            'event_type' => '*',
            'targets'    => [['channel' => 'carrier-pigeon', 'address' => 'x']],
        ]);
    }

    public function testCreateRejectsInvalidEmailAddress(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $resolver   = new RuleResolver($collection);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->create([
            'name'       => 'bad-email',
            'event_type' => '*',
            'targets'    => [['channel' => 'email', 'address' => 'not-an-email']],
        ]);
    }

    public function testCreateRequiresAtLeastOneTarget(): void
    {
        $collection = $this->createMock(\MongoDB\Collection::class);
        $resolver   = new RuleResolver($collection);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->create(['name' => 'empty', 'event_type' => '*', 'targets' => []]);
    }
}
