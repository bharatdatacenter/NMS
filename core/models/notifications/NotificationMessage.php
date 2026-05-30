<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications;

/**
 * NotificationMessage
 *
 * Immutable value object describing a single alert to be delivered across one
 * or more channels. Built once by the producer (scheduler, handler) and
 * rendered per-channel by each ChannelInterface adapter.
 *
 * Severity follows Zabbix trigger priority (0-5) so monitoring alerts map
 * straight through:
 *   0 not_classified, 1 information, 2 warning, 3 average, 4 high, 5 disaster
 */
final class NotificationMessage
{
    public const SEVERITY_LABELS = [
        0 => 'not_classified',
        1 => 'information',
        2 => 'warning',
        3 => 'average',
        4 => 'high',
        5 => 'disaster',
    ];

    public function __construct(
        public readonly string $eventType,
        public readonly int $severity,
        public readonly string $title,
        public readonly string $body,
        public readonly array $sourceRef = [],
    ) {
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? 'unknown';
    }

    /**
     * Clamp/normalize an arbitrary integer into the valid severity range.
     */
    public static function clampSeverity(int $severity): int
    {
        return max(0, min(5, $severity));
    }
}
