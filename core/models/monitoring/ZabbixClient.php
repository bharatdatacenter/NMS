<?php

declare(strict_types=1);

namespace NMS\Core\Models\Monitoring;

use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use Predis\Client as RedisClient;

/**
 * ZabbixClient
 *
 * Read-only proxy to Zabbix JSON-RPC API with Redis caching.
 * Cache keys: monitoring:{zabbixHostId}:{type}
 */
class ZabbixClient
{
    private const HEALTH_TTL = 60;
    private const TRAFFIC_TTL = 60;
    private const ALERTS_TTL = 30;
    private const AVAILABILITY_TTL = 30;

    private RedisClient $redis;
    private SecretsManagerInterface $secrets;
    private string $apiUrl;
    private string $token;

    public function __construct(
        ?RedisClient $redis = null,
        ?SecretsManagerInterface $secrets = null,
        ?array $config = null
    ) {
        $config ??= require dirname(__DIR__, 3) . '/core/config/app.php';

        $this->redis   = $redis ?? new RedisClient(require dirname(__DIR__, 3) . '/core/config/redis.php');
        $this->secrets = $secrets ?? $this->buildSecretsManager();
        $this->apiUrl  = (string)($config['zabbix']['api_url'] ?? '');

        if ($this->apiUrl === '') {
            throw new \RuntimeException('ZABBIX_API_URL is not configured');
        }

        $tokenPath = (string)($config['zabbix']['vault_path'] ?? 'nms/zabbix/api_token');
        $this->token = $this->secrets->get($tokenPath);
        if ($this->token === '') {
            throw new \RuntimeException('Zabbix API token is empty');
        }
    }

    /**
     * Returns CPU/memory/uptime + host availability details.
     */
    public function getHostHealth(string $zabbixHostId): array
    {
        $cacheKey = $this->cacheKey($zabbixHostId, 'health');

        return $this->cached($cacheKey, self::HEALTH_TTL, function () use ($zabbixHostId): array {
            $hosts = $this->rpc('host.get', [
                'output' => ['hostid', 'host', 'name', 'status', 'available'],
                'hostids' => [$zabbixHostId],
            ]);

            if (empty($hosts)) {
                throw new \RuntimeException("Zabbix host not found: {$zabbixHostId}");
            }

            $host = (array)$hosts[0];

            $items = $this->rpc('item.get', [
                'output' => ['itemid', 'name', 'key_', 'lastvalue', 'units', 'lastclock'],
                'hostids' => [$zabbixHostId],
                'search' => ['key_' => [
                    'system.cpu.util',
                    'vm.memory.size',
                    'system.uptime',
                ]],
                'searchByAny' => true,
                'sortfield' => 'lastclock',
                'sortorder' => 'DESC',
            ]);

            $cpu = null;
            $memory = null;
            $uptime = null;

            foreach ($items as $item) {
                $key = (string)($item['key_'] ?? '');
                $value = $item['lastvalue'] ?? null;

                if ($cpu === null && str_starts_with($key, 'system.cpu.util')) {
                    $cpu = $this->toFloat($value);
                    continue;
                }

                if ($memory === null && str_starts_with($key, 'vm.memory.size')) {
                    $memory = $this->toFloat($value);
                    continue;
                }

                if ($uptime === null && str_starts_with($key, 'system.uptime')) {
                    $uptime = (int)($value ?? 0);
                }
            }

            return [
                'host_id' => $zabbixHostId,
                'host_name' => (string)($host['name'] ?? $host['host'] ?? ''),
                'available' => ((int)($host['available'] ?? 0)) === 1,
                'status' => ((int)($host['status'] ?? 1)) === 0 ? 'enabled' : 'disabled',
                'metrics' => [
                    'cpu_percent' => $cpu,
                    'memory_value' => $memory,
                    'uptime_seconds' => $uptime,
                ],
                'polled_at' => gmdate('c'),
            ];
        });
    }

    /**
     * Returns in/out traffic (bps) per interface.
     */
    public function getInterfaceTraffic(string $zabbixHostId): array
    {
        $cacheKey = $this->cacheKey($zabbixHostId, 'traffic');

        return $this->cached($cacheKey, self::TRAFFIC_TTL, function () use ($zabbixHostId): array {
            $items = $this->rpc('item.get', [
                'output' => ['itemid', 'name', 'key_', 'lastvalue', 'units', 'lastclock'],
                'hostids' => [$zabbixHostId],
                'search' => ['key_' => ['net.if.in', 'net.if.out']],
                'searchByAny' => true,
                'sortfield' => 'name',
            ]);

            $byIface = [];

            foreach ($items as $item) {
                $key = (string)($item['key_'] ?? '');
                $iface = $this->extractInterfaceFromKey($key);
                if ($iface === '') {
                    continue;
                }

                $byIface[$iface] ??= [
                    'interface' => $iface,
                    'in_bps' => null,
                    'out_bps' => null,
                ];

                $value = $this->toFloat($item['lastvalue'] ?? null);

                if (str_starts_with($key, 'net.if.in')) {
                    $byIface[$iface]['in_bps'] = $value;
                } elseif (str_starts_with($key, 'net.if.out')) {
                    $byIface[$iface]['out_bps'] = $value;
                }
            }

            return [
                'host_id' => $zabbixHostId,
                'interfaces' => array_values($byIface),
                'polled_at' => gmdate('c'),
            ];
        });
    }

    /**
     * Returns active problem triggers for the host.
     */
    public function getActiveAlerts(string $zabbixHostId): array
    {
        $cacheKey = $this->cacheKey($zabbixHostId, 'alerts');

        return $this->cached($cacheKey, self::ALERTS_TTL, function () use ($zabbixHostId): array {
            $triggers = $this->rpc('trigger.get', [
                'output' => ['triggerid', 'description', 'priority', 'value', 'lastchange'],
                'hostids' => [$zabbixHostId],
                'filter' => ['value' => 1],
                'only_true' => true,
                'active' => true,
                'expandDescription' => true,
            ]);

            $alerts = [];
            foreach ($triggers as $trigger) {
                $alerts[] = [
                    'trigger_id' => (string)($trigger['triggerid'] ?? ''),
                    'description' => (string)($trigger['description'] ?? ''),
                    'severity' => (int)($trigger['priority'] ?? 0),
                    'active' => ((int)($trigger['value'] ?? 0)) === 1,
                    'last_change' => (int)($trigger['lastchange'] ?? 0),
                ];
            }

            return [
                'host_id' => $zabbixHostId,
                'alerts' => $alerts,
                'count' => count($alerts),
                'polled_at' => gmdate('c'),
            ];
        });
    }

    public function getAvailability(string $zabbixHostId): bool
    {
        $cacheKey = $this->cacheKey($zabbixHostId, 'availability');

        $payload = $this->cached($cacheKey, self::AVAILABILITY_TTL, function () use ($zabbixHostId): array {
            $hosts = $this->rpc('host.get', [
                'output' => ['hostid', 'available'],
                'hostids' => [$zabbixHostId],
            ]);

            if (empty($hosts)) {
                return ['available' => false];
            }

            return ['available' => ((int)($hosts[0]['available'] ?? 0)) === 1];
        });

        return (bool)($payload['available'] ?? false);
    }

    /**
     * Resolve mapped host ID from a device document.
     */
    public function resolveHostId(array $device): ?string
    {
        $mapped = (string)($device['zabbix']['host_id'] ?? '');
        if ($mapped !== '') {
            return $mapped;
        }

        $nameCandidates = array_values(array_filter([
            $device['zabbix']['host_name'] ?? null,
            $device['hostname'] ?? null,
            $device['name'] ?? null,
            $device['ip_address'] ?? null,
        ], fn($v) => is_string($v) && $v !== ''));

        foreach ($nameCandidates as $candidate) {
            $hosts = $this->rpc('host.get', [
                'output' => ['hostid', 'host', 'name'],
                'search' => ['host' => $candidate, 'name' => $candidate],
                'searchByAny' => true,
                'limit' => 1,
            ]);

            if (!empty($hosts[0]['hostid'])) {
                return (string)$hosts[0]['hostid'];
            }
        }

        return null;
    }

    private function cached(string $cacheKey, int $ttl, callable $producer): array
    {
        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $decoded = json_decode((string)$cached, true);
                if (is_array($decoded)) {
                    $decoded['_cache'] = 'hit';
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Redis outage should not block monitoring reads.
        }

        $fresh = $producer();
        $fresh['_cache'] = 'miss';

        try {
            $this->redis->set($cacheKey, json_encode($fresh), 'EX', $ttl);
        } catch (\Throwable) {
            // Best-effort cache write.
        }

        return $fresh;
    }

    private function cacheKey(string $hostId, string $type): string
    {
        return "monitoring:{$hostId}:{$type}";
    }

    protected function rpc(string $method, array $params = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'auth' => $this->token,
            'id' => random_int(1000, 999999),
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json-rpc',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErr !== '') {
            throw new \RuntimeException("Zabbix API curl error: {$curlErr}");
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Zabbix API returned invalid JSON');
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Zabbix API HTTP {$httpCode}");
        }

        if (isset($decoded['error'])) {
            $message = (string)($decoded['error']['data'] ?? $decoded['error']['message'] ?? 'Unknown Zabbix error');
            throw new \RuntimeException("Zabbix API error ({$method}): {$message}");
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    private function extractInterfaceFromKey(string $key): string
    {
        if (preg_match('/^net\\.if\\.(?:in|out)\\[([^,\]]+)/', $key, $m) === 1) {
            return trim($m[1]);
        }
        return '';
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    private function buildSecretsManager(): SecretsManagerInterface
    {
        $vaultConfig = require dirname(__DIR__, 3) . '/core/config/vault.php';
        if (!empty($vaultConfig['enabled'])) {
            return new VaultSecretsManager($vaultConfig);
        }
        return new AppEncryptedSecretsManager($vaultConfig);
    }
}
