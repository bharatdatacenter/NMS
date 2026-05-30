<?php
// One-off helper: store SMTP password + Telegram bot token in the secrets backend.
// Usage:  php store-notification-secrets.php "<smtp_password>" "<telegram_bot_token>"
// Requires NMS_ENCRYPTION_KEY env var set (64 hex chars) when Vault is not used.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/core/config/app.php';

use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;

$smtp = $argv[1] ?? '';
$tg   = $argv[2] ?? '';
if ($smtp === '' && $tg === '') {
    fwrite(STDERR, "Usage: php store-notification-secrets.php \"<smtp_password>\" \"<telegram_bot_token>\"\n");
    exit(1);
}

$vaultConfig = require __DIR__ . '/core/config/vault.php';
$secrets = !empty($vaultConfig['enabled'])
    ? new VaultSecretsManager($vaultConfig)
    : new AppEncryptedSecretsManager($vaultConfig);

if ($smtp !== '') {
    $secrets->put(getenv('SMTP_VAULT_PATH') ?: 'nms/notifications/smtp_password', $smtp);
    echo "Stored SMTP password.\n";
}
if ($tg !== '') {
    $secrets->put(getenv('TELEGRAM_BOT_VAULT_PATH') ?: 'nms/notifications/telegram_bot_token', $tg);
    echo "Stored Telegram bot token.\n";
}
echo "Done.\n";
