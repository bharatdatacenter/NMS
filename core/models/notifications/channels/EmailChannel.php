<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications\Channels;

use NMS\Core\Models\Notifications\ChannelInterface;
use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Notifications\SmtpMailer;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Core\Models\Secrets\VaultSecretsManager;

/**
 * EmailChannel
 *
 * Delivers alerts over SMTP. The SMTP password is resolved from the
 * SecretsManager (Vault reference in config), never read from Mongo or stored
 * in plaintext config — consistent with the project's secrets rule.
 *
 * The SMTP transport is injected for testability; in production it is built
 * lazily per-send so a fresh authenticated connection is used each time.
 */
class EmailChannel implements ChannelInterface
{
    private array $config;
    private ?SecretsManagerInterface $secrets;
    /** @var null|callable(string $password):SmtpMailer */
    private $mailerFactory;

    /**
     * @param array|null                   $config        notifications.php['email']
     * @param SecretsManagerInterface|null $secrets       secrets backend (auto-built if null)
     * @param callable|null                $mailerFactory fn(string $password): SmtpMailer — for tests
     */
    public function __construct(
        ?array $config = null,
        ?SecretsManagerInterface $secrets = null,
        ?callable $mailerFactory = null
    ) {
        if ($config === null) {
            $all = require dirname(__DIR__, 3) . '/config/notifications.php';
            $config = $all['email'] ?? [];
        }
        $this->config        = $config;
        $this->secrets       = $secrets;
        $this->mailerFactory = $mailerFactory;
    }

    public function name(): string
    {
        return 'email';
    }

    public function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false)
            && (string)($this->config['host'] ?? '') !== '';
    }

    public function send(NotificationMessage $message, string $target): string
    {
        if (filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
            // Bad recipient — not retryable.
            throw new \RuntimeException("Invalid email recipient: {$target}", 400);
        }

        $subject = '[' . strtoupper($message->severityLabel()) . '] ' . $message->title;
        $text    = $this->render('alert.txt.php', $message);
        $html    = $this->render('alert.html.php', $message);

        $mailer = $this->buildMailer();

        $reply = $mailer->send(
            (string)($this->config['from_email'] ?? 'nms@localhost'),
            (string)($this->config['from_name'] ?? 'NMS Alerts'),
            $target,
            $subject,
            $text,
            $html
        );

        return $reply;
    }

    private function buildMailer(): SmtpMailer
    {
        $password = $this->resolvePassword();

        if ($this->mailerFactory !== null) {
            return ($this->mailerFactory)($password);
        }

        return new SmtpMailer(
            (string)($this->config['host'] ?? ''),
            (int)($this->config['port'] ?? 587),
            (string)($this->config['encryption'] ?? 'tls'),
            (string)($this->config['username'] ?? ''),
            $password,
            (int)($this->config['timeout'] ?? 10),
        );
    }

    private function resolvePassword(): string
    {
        $username = (string)($this->config['username'] ?? '');
        if ($username === '') {
            return ''; // unauthenticated relay
        }

        $path = (string)($this->config['vault_path'] ?? '');
        if ($path === '') {
            return '';
        }

        return $this->secrets()->get($path);
    }

    private function render(string $template, NotificationMessage $message): string
    {
        $path = dirname(__DIR__) . '/templates/' . $template;
        // $message is referenced inside the template scope.
        return (string)(require $path);
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
