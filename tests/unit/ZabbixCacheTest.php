<?php

declare(strict_types=1);

namespace Tests\Unit;

use NMS\Core\Models\Monitoring\ZabbixClient;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class ZabbixCacheTest extends TestCase
{
    public function testHealthUsesCacheWithinTtlAndRefreshesAfterExpiry(): void
    {
        $redis = new FakeRedisClient();
        $secrets = new StubSecretsManager();

        $client = new StubZabbixClient(
            $redis,
            $secrets,
            [
                'zabbix' => [
                    'api_url' => 'http://zabbix.local/api_jsonrpc.php',
                    'vault_path' => 'nms/zabbix/api_token',
                ],
            ]
        );

        $first = $client->getHostHealth('101');
        $second = $client->getHostHealth('101');

        $this->assertSame('miss', $first['_cache']);
        $this->assertSame('hit', $second['_cache']);
        $this->assertSame(2, $client->rpcCalls);

        $redis->advance(61);
        $third = $client->getHostHealth('101');

        $this->assertSame('miss', $third['_cache']);
        $this->assertSame(4, $client->rpcCalls);
    }
}

class StubZabbixClient extends ZabbixClient
{
    public int $rpcCalls = 0;

    protected function rpc(string $method, array $params = []): array
    {
        $this->rpcCalls++;

        if ($method === 'host.get') {
            return [[
                'hostid' => '101',
                'host' => 'fw-01',
                'name' => 'fw-01',
                'status' => '0',
                'available' => '1',
            ]];
        }

        if ($method === 'item.get') {
            return [
                ['key_' => 'system.cpu.util', 'lastvalue' => '17.2'],
                ['key_' => 'vm.memory.size[pavailable]', 'lastvalue' => '63.1'],
                ['key_' => 'system.uptime', 'lastvalue' => '3600'],
            ];
        }

        if ($method === 'trigger.get') {
            return [];
        }

        return [];
    }
}

class StubSecretsManager implements SecretsManagerInterface
{
    public function get(string $path): string
    {
        return 'stub-zabbix-token';
    }

    public function put(string $path, string $value): void
    {
    }

    public function delete(string $path): void
    {
    }

    public function exists(string $path): bool
    {
        return true;
    }
}

class FakeRedisClient extends RedisClient
{
    private array $store = [];
    private int $clock = 0;

    public function __construct()
    {
    }

    public function get($key)
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];
        if ($entry['expires_at'] !== null && $entry['expires_at'] <= $this->clock) {
            unset($this->store[$key]);
            return null;
        }

        return $entry['value'];
    }

    public function set($key, $value, ...$args)
    {
        $expiresAt = null;

        if (count($args) >= 2 && strtoupper((string)$args[0]) === 'EX') {
            $ttl = (int)$args[1];
            $expiresAt = $this->clock + $ttl;
        }

        $this->store[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        return 'OK';
    }

    public function advance(int $seconds): void
    {
        $this->clock += $seconds;
    }
}
