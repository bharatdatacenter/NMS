<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Settings;

use NMS\Core\Helpers\Response;
use Predis\Client as RedisClient;

/**
 * RedisHealthHandler
 *
 * GET /api/settings/redis/health — check Redis connectivity, memory, and uptime
 */
class RedisHealthHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'GET') {
            Response::error('Method not allowed', 405);
            return;
        }

        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.settings.read', $perms, true)) {
            Response::error('Forbidden: nms.settings.read required', 403);
            return;
        }

        $health = $this->checkRedis();
        $status = $health['healthy'] ? 200 : 503;
        Response::json(['data' => $health], $status);
    }

    private function checkRedis(): array
    {
        $result = [
            'healthy'    => false,
            'error'      => null,
            'latency_ms' => null,
            'info'       => null,
        ];

        $start = microtime(true);
        try {
            $config = require dirname(__DIR__, 4) . '/core/config/redis.php';
            $redis  = new RedisClient($config);

            // Ping
            $pong = $redis->ping();
            if ($pong !== 'PONG' && !($pong instanceof \Predis\Response\Status)) {
                throw new \RuntimeException('Redis ping did not return PONG');
            }

            $result['healthy'] = true;

            // Collect INFO stats
            $info = $redis->info();
            if (is_array($info)) {
                $server  = $info['Server'] ?? $info['server'] ?? [];
                $memory  = $info['Memory'] ?? $info['memory'] ?? [];
                $stats   = $info['Stats'] ?? $info['stats'] ?? [];
                $clients = $info['Clients'] ?? $info['clients'] ?? [];

                $result['info'] = [
                    'version'            => $server['redis_version'] ?? null,
                    'uptime_seconds'     => isset($server['uptime_in_seconds']) ? (int)$server['uptime_in_seconds'] : null,
                    'used_memory_human'  => $memory['used_memory_human'] ?? null,
                    'maxmemory_human'    => $memory['maxmemory_human'] ?? null,
                    'connected_clients'  => isset($clients['connected_clients']) ? (int)$clients['connected_clients'] : null,
                    'total_commands'     => isset($stats['total_commands_processed']) ? (int)$stats['total_commands_processed'] : null,
                    'keyspace_hits'      => isset($stats['keyspace_hits']) ? (int)$stats['keyspace_hits'] : null,
                    'keyspace_misses'    => isset($stats['keyspace_misses']) ? (int)$stats['keyspace_misses'] : null,
                ];
            }

            // Check key count used by NMS
            $nmsKeys = $redis->dbsize();
            $result['nms_key_count'] = $nmsKeys;

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['latency_ms'] = (int)((microtime(true) - $start) * 1000);
        $result['checked_at'] = date('c');
        return $result;
    }
}
