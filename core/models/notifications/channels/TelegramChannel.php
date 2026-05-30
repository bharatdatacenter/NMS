<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications\Channels;

use NMS\Core\Models\Notifications\ChannelInterface;
use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * TelegramChannel
 *
 * Delivers alerts via the Telegram Bot API (sendMessage). The bot token is
 * resolved from the SecretsManager (Vault reference), never stored in config
 * or Mongo. HTTP is done with curl, matching ZabbixClient / ImsTicketClient.
 *
 * Target = numeric chat id (user, group, or channel the bot is a member of).
 */
class TelegramChannel implements ChannelInterface
{
    private const MAX_MESSAGE_LEN = 4096;

    private array $config;
    private ?SecretsManagerInterface $secrets;

    public function __construct(?array $config = null, ?SecretsManagerInterface $secrets = null)
    {
        if ($config === null) {
            $all = require dirname(__DIR__, 3) . '/config/notifications.php';
            $config = $all['telegram'] ?? [];
        }
        $this->config  = $config;
        $this->secrets = $secrets;
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false);
    }

    public function send(NotificationMessage $message, string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            throw new \RuntimeException('Telegram chat id is empty', 400);
        }

        $token = $this->resolveToken();
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token is not configured', 400);
        }

        $text = $this->render($message);
        if (mb_strlen($text) > self::MAX_MESSAGE_LEN) {
            $text = mb_substr($text, 0, self::MAX_MESSAGE_LEN - 1) . "\u{2026}";
        }

        $apiBase = rtrim((string)($this->config['api_url'] ?? 'https://api.telegram.org'), '/');
        $url = "{$apiBase}/bot{$token}/sendMessage";

        $response = $this->httpPost($url, [
            'chat_id' => $target,
            'text'    => $text,
            'disable_web_page_preview' => true,
        ]);

        if (($response['ok'] ?? false) !== true) {
            $desc = (string)($response['description'] ?? 'unknown error');
            $code = (int)($response['error_code'] ?? 0);
            // 429 / 5xx → retryable (RetryHandler reads the exception code).
            throw new \RuntimeException("Telegram API error: {$desc}", $code);
        }

        return (string)($response['result']['message_id'] ?? '');
    }

    private function render(NotificationMessage $message): string
    {
        $path = dirname(__DIR__) . '/templates/alert.txt.php';
        return (string)(require $path);
    }

    private function resolveToken(): string
    {
        $path = (string)($this->config['vault_path'] ?? '');
        if ($path === '') {
            return '';
        }
        return $this->secrets()->get($path);
    }

    /**
     * Perform the HTTP POST. Protected so tests can override without a network.
     *
     * @return array Decoded Telegram API response.
     */
    protected function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)($this->config['timeout'] ?? 10),
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErr !== '') {
            // curl-level failure (timeout / connection) → retryable.
            throw new \RuntimeException("Telegram curl error: {$curlErr}", 503);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Telegram returned invalid JSON (HTTP {$httpCode})", $httpCode);
        }

        return $decoded;
    }

    private function secrets(): SecretsManagerInterface
    {
        if ($this->secrets === null) {
            $vaultConfig = require dirname(__DIR__, 3) . '/config/vault.php';
            $this->secrets = !empty($vaultConfig['enabled'])
                ? new VaultSecretsManager($vaultConfig)
                : new AppEncryptedSecretsManager($vaultConfig);
        }
        return $this->secrets;
    }
}
