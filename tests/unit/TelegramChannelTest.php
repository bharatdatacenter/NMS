<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Models\Notifications\Channels\TelegramChannel;
use NMS\Core\Models\Notifications\NotificationMessage;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * TelegramChannelTest
 *
 * Verifies payload construction and API response handling without any network.
 * httpPost() is overridden via an anonymous subclass, and the bot token is
 * supplied through a fake SecretsManager.
 */
class TelegramChannelTest extends TestCase
{
    private function fakeSecrets(string $token = 'bot-token'): SecretsManagerInterface
    {
        return new class($token) implements SecretsManagerInterface {
            public function __construct(private string $token) {}
            public function get(string $path): string { return $this->token; }
            public function put(string $path, string $v): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool { return true; }
        };
    }

    private function message(): NotificationMessage
    {
        return new NotificationMessage('drift_detected', 4, 'Device down', 'Body text', ['device_id' => 'd1']);
    }

    public function testSendPostsToCorrectUrlWithChatId(): void
    {
        $config = [
            'enabled'    => true,
            'api_url'    => 'https://api.telegram.org',
            'vault_path' => 'nms/notifications/telegram_bot_token',
            'timeout'    => 5,
        ];

        $captured = [];

        $channel = new class($config, $this->fakeSecrets(), $captured) extends TelegramChannel {
            public array $captured;
            public function __construct(array $config, $secrets, array &$captured)
            {
                parent::__construct($config, $secrets);
                $this->captured = &$captured;
            }
            protected function httpPost(string $url, array $payload): array
            {
                $this->captured['url'] = $url;
                $this->captured['payload'] = $payload;
                return ['ok' => true, 'result' => ['message_id' => 4242]];
            }
        };

        $messageId = $channel->send($this->message(), '987654');

        $this->assertSame('4242', $messageId);
        $this->assertSame('https://api.telegram.org/botbot-token/sendMessage', $captured['url']);
        $this->assertSame('987654', $captured['payload']['chat_id']);
        $this->assertStringContainsString('Device down', $captured['payload']['text']);
    }

    public function testSendThrowsOnApiError(): void
    {
        $config = ['enabled' => true, 'vault_path' => 'p'];

        $channel = new class($config, $this->fakeSecrets()) extends TelegramChannel {
            protected function httpPost(string $url, array $payload): array
            {
                return ['ok' => false, 'error_code' => 400, 'description' => 'chat not found'];
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chat not found');
        $channel->send($this->message(), 'bad-chat');
    }

    public function testSendThrowsOnEmptyTarget(): void
    {
        $channel = new TelegramChannel(['enabled' => true, 'vault_path' => 'p'], $this->fakeSecrets());

        $this->expectException(\RuntimeException::class);
        $channel->send($this->message(), '   ');
    }

    public function testIsEnabledReflectsConfig(): void
    {
        $on  = new TelegramChannel(['enabled' => true], $this->fakeSecrets());
        $off = new TelegramChannel(['enabled' => false], $this->fakeSecrets());

        $this->assertTrue($on->isEnabled());
        $this->assertFalse($off->isEnabled());
    }
}
