<?php

declare(strict_types=1);

/**
 * Notification channel configuration.
 *
 * Secrets (SMTP password, Telegram bot token) are NEVER stored here — only
 * Vault path references. The actual values are resolved at send time via the
 * SecretsManager, exactly like ZabbixClient / ImsTicketClient.
 *
 * Loaded by NotificationManager. Relies on env vars already populated by
 * core/config/app.php (which parses .env).
 */

return [
    // Master kill-switch. When false, NotificationManager::dispatch() is a no-op.
    'enabled' => filter_var(getenv('NOTIFICATIONS_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),

    'email' => [
        'enabled'    => filter_var(getenv('SMTP_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'host'       => getenv('SMTP_HOST') ?: '',
        'port'       => (int)(getenv('SMTP_PORT') ?: 587),
        // 'tls' (STARTTLS, port 587), 'ssl' (implicit TLS, port 465), or 'none'
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'username'   => getenv('SMTP_USERNAME') ?: '',
        // Vault reference for the SMTP password — never the password itself.
        'vault_path' => getenv('SMTP_VAULT_PATH') ?: 'nms/notifications/smtp_password',
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'nms@localhost',
        'from_name'  => getenv('SMTP_FROM_NAME') ?: 'NMS Alerts',
        'timeout'    => (int)(getenv('SMTP_TIMEOUT') ?: 10),
    ],

    'telegram' => [
        'enabled'    => filter_var(getenv('TELEGRAM_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'api_url'    => getenv('TELEGRAM_API_URL') ?: 'https://api.telegram.org',
        // Vault reference for the bot token — never the token itself.
        'vault_path' => getenv('TELEGRAM_BOT_VAULT_PATH') ?: 'nms/notifications/telegram_bot_token',
        // Optional fallback chat id used by /notifications/test when no target given.
        'default_chat_id' => getenv('TELEGRAM_DEFAULT_CHAT_ID') ?: '',
        'timeout'    => (int)(getenv('TELEGRAM_TIMEOUT') ?: 10),
    ],
];
