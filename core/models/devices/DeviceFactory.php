<?php

declare(strict_types=1);

namespace NMS\Core\Models\Devices;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\FortiGate\FortiGateAdapter;
use NMS\Vendors\MikroTik\MikroTikAdapter;
use NMS\Vendors\VyOS\VyOSAdapter;
use Predis\Client as RedisClient;

/**
 * DeviceFactory
 *
 * Returns the correct vendor adapter for a device document.
 * Loads credentials from Vault via the device's vault_path.
 * All adapters are wrapped with RetryHandler + CircuitBreaker (via VendorAdapter base).
 */
class DeviceFactory
{
    /**
     * Create a vendor adapter for the given device.
     *
     * @param  array                   $device   Device document from MongoDB (must have 'vendor', 'ip_address', 'vault_path')
     * @param  SecretsManagerInterface $secrets  Secrets manager (Vault or encrypted fallback)
     * @param  RedisClient|null        $redis    Redis client for circuit breaker state; instantiated from config if null
     * @return DeviceInterface|null              null if vendor not supported
     */
    public static function create(
        array $device,
        SecretsManagerInterface $secrets,
        ?RedisClient $redis = null
    ): ?DeviceInterface {
        $redis ??= self::getRedis();

        return match ($device['vendor'] ?? '') {
            'mikrotik'  => new MikroTikAdapter($device, $secrets, $redis),
            'fortigate' => new FortiGateAdapter($device, $secrets, $redis),
            'vyos'      => new VyOSAdapter($device, $secrets, $redis),
            default     => null,
        };
    }

    /**
     * Check if a vendor string has a supported adapter.
     */
    public static function isSupported(string $vendor): bool
    {
        return in_array($vendor, ['mikrotik', 'fortigate', 'vyos'], true);
    }

    /**
     * Return list of supported vendor strings.
     */
    public static function supportedVendors(): array
    {
        return ['mikrotik', 'fortigate', 'vyos'];
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private static ?RedisClient $redis = null;

    private static function getRedis(): RedisClient
    {
        if (self::$redis === null) {
            $config      = require dirname(__DIR__, 3) . '/core/config/redis.php';
            self::$redis = new RedisClient($config);
        }
        return self::$redis;
    }
}
