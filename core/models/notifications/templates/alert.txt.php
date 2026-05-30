<?php
/**
 * Plain-text alert template.
 *
 * @var \NMS\Core\Models\Notifications\NotificationMessage $message
 * Returns a string (the rendered body). Used by EmailChannel and TelegramChannel.
 */

declare(strict_types=1);

$lines = [];
$lines[] = '[' . strtoupper($message->severityLabel()) . '] ' . $message->title;
$lines[] = str_repeat('-', 48);
$lines[] = $message->body;

if (!empty($message->sourceRef)) {
    $lines[] = '';
    $lines[] = 'Reference:';
    foreach ($message->sourceRef as $key => $value) {
        if (is_scalar($value)) {
            $lines[] = '  ' . $key . ': ' . (string)$value;
        }
    }
}

$lines[] = '';
$lines[] = 'Event: ' . $message->eventType;
$lines[] = 'Sent:  ' . gmdate('c');
$lines[] = '-- NMS Network Management System';

return implode("\n", $lines);
