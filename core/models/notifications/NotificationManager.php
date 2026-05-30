<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications;

use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\CircuitBreaker;
use NMS\Core\Helpers\Logger;
use NMS\Core\Helpers\RetryHandler;
use NMS\Core\Models\Notifications\Channels\EmailChannel;
use NMS\Core\Models\Notifications\Channels\TelegramChannel;
use Predis\Client as RedisClient;

/**
 * NotificationManager
 *
 * Central dispatcher for multi-channel alert delivery. The single entry point
 * is dispatch(): it resolves matching rules into targets, then delivers each
 * via its channel adapter — every send wrapped in RetryHandler + a per-channel
 * CircuitBreaker (reusing the same resilience helpers as the vendor layer).
 *
 * Every attempt is recorded in `notification_log` for audit + troubleshooting.
 *
 * Failure is always contained: a dead channel, a missing token, or a Redis
 * outage degrades gracefully and never bubbles up to the caller (a scheduler
 * scan or an API handler must not break because an alert could not be sent).
 */
class NotificationManager
{
    private array $config;
    private RuleResolver $resolver;
    private \MongoDB\Collection $log;
    private RetryHandler $retry;
    private ?CircuitBreaker $breaker;
    /** @var array<string,ChannelInterface> */
    private array $channels;

    /**
     * @param array<string,ChannelInterface>|null $channels keyed by channel name (for tests)
     * @param CircuitBreaker|false|null            $breaker  false = auto-build (default),
     *                                                       null = explicitly disabled,
     *                                                       instance = use as given
     */
    public function __construct(
        ?array $config = null,
        ?RuleResolver $resolver = null,
        ?\MongoDB\Collection $log = null,
        ?array $channels = null,
        ?RetryHandler $retry = null,
        CircuitBreaker|false|null $breaker = false,
    ) {
        $this->config   = $config ?? require dirname(__DIR__, 3) . '/core/config/notifications.php';
        $this->resolver = $resolver ?? new RuleResolver();
        $this->log      = $log ?? MongoDB::getInstance()->selectCollection('notification_log');
        $this->retry    = $retry ?? new RetryHandler(maxRetries: 2, baseDelayMs: 500, maxDelayMs: 5000);
        $this->breaker  = $breaker === false ? $this->buildBreaker() : $breaker;
        $this->channels = $channels ?? $this->buildChannels();
    }

    /**
     * Deliver a message across every target resolved from notification_rules.
     *
     * @return array{sent:int,failed:int,skipped:int,targets:int}
     */
    public function dispatch(NotificationMessage $message): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targets' => 0];

        if (!($this->config['enabled'] ?? false)) {
            return $summary;
        }

        try {
            $targets = $this->resolver->resolve($message);
        } catch (\Throwable $e) {
            Logger::error('Notification rule resolution failed', ['error' => $e->getMessage()]);
            return $summary;
        }

        $summary['targets'] = count($targets);

        foreach ($targets as $target) {
            $channelName = $target['channel'];
            $address     = $target['address'];
            $channel     = $this->channels[$channelName] ?? null;

            if ($channel === null || !$channel->isEnabled()) {
                $summary['skipped']++;
                $this->record($message, $target, 'skipped', '', 'channel disabled or unavailable');
                continue;
            }

            try {
                $providerId = $this->deliver($channel, $message, $address);
                $summary['sent']++;
                $this->record($message, $target, 'sent', $providerId, '');
            } catch (\Throwable $e) {
                $summary['failed']++;
                $this->record($message, $target, 'failed', '', $e->getMessage());
                Logger::warning('Notification delivery failed', [
                    'channel'    => $channelName,
                    'event_type' => $message->eventType,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Send through the circuit breaker (per channel) + retry handler.
     */
    private function deliver(ChannelInterface $channel, NotificationMessage $message, string $address): string
    {
        $send = fn(): string => $this->retry->withRetry(
            fn() => $channel->send($message, $address)
        );

        if ($this->breaker !== null) {
            // Circuit key is per-channel so one dead channel does not trip others.
            return (string)$this->breaker->call('notify:' . $channel->name(), $send);
        }

        return $send();
    }

    private function record(
        NotificationMessage $message,
        array $target,
        string $status,
        string $providerId,
        string $error
    ): void {
        try {
            $this->log->insertOne([
                'event_type'  => $message->eventType,
                'severity'    => $message->severity,
                'title'       => $message->title,
                'channel'     => $target['channel'],
                'recipient'   => $target['address'],
                'rule_id'     => $target['rule_id'] ?? '',
                'rule_name'   => $target['rule_name'] ?? '',
                'status'      => $status,
                'provider_id' => $providerId,
                'error'       => $error,
                'source_ref'  => $message->sourceRef,
                'created_at'  => new UTCDateTime(),
            ]);
        } catch (\Throwable $e) {
            // Logging the delivery must never break delivery.
            Logger::warning('Failed to write notification_log entry', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string,ChannelInterface>
     */
    private function buildChannels(): array
    {
        $channels = [];
        try {
            $channels['email'] = new EmailChannel($this->config['email'] ?? null);
        } catch (\Throwable $e) {
            Logger::warning('EmailChannel init failed', ['error' => $e->getMessage()]);
        }
        try {
            $channels['telegram'] = new TelegramChannel($this->config['telegram'] ?? null);
        } catch (\Throwable $e) {
            Logger::warning('TelegramChannel init failed', ['error' => $e->getMessage()]);
        }
        return $channels;
    }

    private function buildBreaker(): ?CircuitBreaker
    {
        try {
            $redis = new RedisClient(require dirname(__DIR__, 3) . '/core/config/redis.php');
            return new CircuitBreaker($redis);
        } catch (\Throwable) {
            // Redis unavailable — degrade to no circuit breaker rather than failing.
            return null;
        }
    }
}
