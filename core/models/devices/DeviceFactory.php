<?php

declare(strict_types=1);

namespace NMS\Core\Models\Devices;

use NMS\Core\Models\Secrets\SecretsManagerInterface;

/**
 * DeviceFactory
 *
 * Returns the correct vendor adapter for a device.
 * Stub for Phase 2 — full implementation in Phase 3 (vendor adapters).
 */
class DeviceFactory
{
    /**
     * Create a vendor adapter for the given device.
     *
     * @param array                   $device  Device document from MongoDB
     * @param SecretsManagerInterface $secrets Secrets manager to load credentials from Vault
     *
     * @return DeviceInterface|null null until Phase 3 vendor adapters are implemented
     */
    public static function create(array $device, SecretsManagerInterface $secrets): ?DeviceInterface
    {
        // Phase 3 will implement this:
        // return match($device['vendor'] ?? '') {
        //     'mikrotik'  => new \NMS\Vendors\MikroTik\MikroTikAdapter($device, $secrets),
        //     'fortigate' => new \NMS\Vendors\FortiGate\FortiGateAdapter($device, $secrets),
        //     'vyos'      => new \NMS\Vendors\VyOS\VyOSAdapter($device, $secrets),
        //     'cisco'     => new \NMS\Vendors\Cisco\CiscoAdapter($device, $secrets),
        //     'aruba'     => new \NMS\Vendors\Aruba\ArubaAdapter($device, $secrets),
        //     default     => null,
        // };
        return null;
    }
}
