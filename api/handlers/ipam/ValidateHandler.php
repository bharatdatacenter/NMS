<?php

declare(strict_types=1);

/**
 * POST /api/ipam/validate
 *
 * Validate an IP address or CIDR — returns parse results and any NMS conflicts.
 * Does NOT allocate anything.
 *
 * Required: ip or network (at least one)
 * Optional: pool_id (if provided, checks conflict within that pool)
 */

use NMS\Core\Models\Ipam\ConflictChecker;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\IPUtils;

try {
    $ip      = $body['ip'] ?? null;
    $network = $body['network'] ?? null;
    $poolId  = $body['pool_id'] ?? null;

    if (!$ip && !$network) {
        Response::unprocessable('Provide ip or network', ['ip' => 'Required if network not provided']);
    }

    $result = [];

    // Validate IP
    if ($ip) {
        $valid = IPUtils::isIPv4($ip) || IPUtils::isIPv6($ip);
        $result['ip'] = [
            'value'      => $ip,
            'valid'      => $valid,
            'ip_version' => $valid ? (IPUtils::isIPv4($ip) ? 'ipv4' : 'ipv6') : null,
        ];

        if ($valid && $poolId) {
            $checker = new ConflictChecker();
            $result['ip']['conflict_in_pool'] = $checker->checkConflict($ip, $poolId);
        }
    }

    // Validate CIDR
    if ($network) {
        try {
            $parsed = IPUtils::parseCIDR($network);
            $range  = IPUtils::getUsableRange($network);

            $result['network'] = [
                'value'           => $network,
                'valid'           => true,
                'ip_version'      => $parsed['version'] === 4 ? 'ipv4' : 'ipv6',
                'prefix_length'   => $parsed['prefix'],
                'first_usable_ip' => $range['first'],
                'last_usable_ip'  => $range['last'],
                'total_addresses' => $range['total'],
            ];
        } catch (\InvalidArgumentException $e) {
            $result['network'] = [
                'value'  => $network,
                'valid'  => false,
                'error'  => $e->getMessage(),
            ];
        }
    }

    Response::json(['data' => $result]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Validation failed: ' . $e->getMessage(), 500);
}
