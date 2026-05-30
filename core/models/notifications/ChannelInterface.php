<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications;

/**
 * ChannelInterface
 *
 * Contract every delivery channel implements (Email, Telegram, and later
 * SMS / WhatsApp / Push). Mirrors the adapter pattern used by the vendor
 * layer — the dispatcher (NotificationManager) depends only on this interface,
 * so adding a channel is a new adapter + config, with no dispatcher change.
 */
interface ChannelInterface
{
    /**
     * Channel identifier, e.g. "email" or "telegram".
     * Must match the value used in notification_rules targets.
     */
    public function name(): string;

    /**
     * Whether this channel is configured and enabled. The dispatcher skips
     * disabled channels rather than treating them as a delivery failure.
     */
    public function isEnabled(): bool;

    /**
     * Deliver a message to a single resolved target.
     *
     * @param NotificationMessage $message
     * @param string              $target  Channel-specific address
     *                                      (email address, Telegram chat id).
     * @return string  Provider-side reference id when available (message id),
     *                 or '' if the provider returns none.
     * @throws \RuntimeException on delivery failure. The dispatcher wraps the
     *         call in RetryHandler + CircuitBreaker; throwing a retryable
     *         RuntimeException (timeout / 5xx / 429) triggers a retry.
     */
    public function send(NotificationMessage $message, string $target): string;
}
