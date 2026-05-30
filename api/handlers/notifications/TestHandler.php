<?php

declare(strict_types=1);

/**
 * POST /api/notifications/test — send a test alert to one channel/target.
 *
 * Bypasses notification_rules so an operator can verify channel credentials
 * directly. Body: { channel: "email"|"telegram", target: string, severity?: int }
 *
 * For Telegram, target may be omitted to fall back to the configured
 * default_chat_id.
 */

use MongoDB\BSON\UTCDateTime;
use NMS\Core\Database\MongoDB;
use NMS\Core\Helpers\Response;
use NMS\Core\Models\Notifications\Channels\EmailChannel;
use NMS\Core\Models\Notifications\Channels\TelegramChannel;
use NMS\Core\Models\Notifications\NotificationMessage;

try {
    $body    = $request['body'] ?? [];
    $channel = strtolower(trim((string)($body['channel'] ?? '')));
    $target  = trim((string)($body['target'] ?? ''));
    $severity = NotificationMessage::clampSeverity((int)($body['severity'] ?? 1));

    $notifConfig = require dirname(__DIR__, 3) . '/core/config/notifications.php';

    $adapter = match ($channel) {
        'email'    => new EmailChannel($notifConfig['email'] ?? null),
        'telegram' => new TelegramChannel($notifConfig['telegram'] ?? null),
        default    => null,
    };

    if ($adapter === null) {
        Response::unprocessable("Invalid channel '{$channel}'. Allowed: email, telegram");
    }

    if ($channel === 'telegram' && $target === '') {
        $target = (string)($notifConfig['telegram']['default_chat_id'] ?? '');
    }
    if ($target === '') {
        Response::unprocessable('A target recipient is required');
    }

    if (!$adapter->isEnabled()) {
        Response::unprocessable("Channel '{$channel}' is not enabled in configuration");
    }

    $message = new NotificationMessage(
        eventType: 'test',
        severity: $severity,
        title: 'NMS test notification',
        body: 'This is a test alert from NMS to verify the ' . $channel . ' delivery channel is configured correctly.',
        sourceRef: ['triggered_by' => (string)($request['user']['sub'] ?? 'unknown')]
    );

    $status = 'sent';
    $providerId = '';
    $error = '';

    try {
        $providerId = $adapter->send($message, $target);
    } catch (\Throwable $e) {
        $status = 'failed';
        $error = $e->getMessage();
    }

    // Record the test in the same audit log as real deliveries.
    try {
        MongoDB::getInstance()->selectCollection('notification_log')->insertOne([
            'event_type'  => 'test',
            'severity'    => $severity,
            'title'       => $message->title,
            'channel'     => $channel,
            'recipient'   => $target,
            'rule_id'     => '',
            'rule_name'   => 'manual-test',
            'status'      => $status,
            'provider_id' => $providerId,
            'error'       => $error,
            'source_ref'  => $message->sourceRef,
            'created_at'  => new UTCDateTime(),
        ]);
    } catch (\Throwable) {
        // Best-effort log.
    }

    if ($status === 'failed') {
        Response::error('Test notification failed: ' . $error, 502, [
            'channel' => $channel,
            'target'  => $target,
        ]);
    }

    Response::json([
        'data' => [
            'status'      => $status,
            'channel'     => $channel,
            'target'      => $target,
            'provider_id' => $providerId,
        ],
    ]);
} catch (Response) {
    // Already sent.
} catch (\Exception $e) {
    Response::error('Failed to send test notification: ' . $e->getMessage(), 500);
}
