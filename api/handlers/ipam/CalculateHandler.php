<?php

declare(strict_types=1);

/**
 * POST /api/ipam/calculate
 *
 * Perform CIDR subnet calculations without storing anything.
 * Useful for planning — pass a CIDR, get back usable range, total count, etc.
 *
 * Required: network (CIDR string)
 * Optional: subprefix_len (int, default 64 — for IPv6 /64 counting)
 */

use NMS\Core\Models\Ipam\SubnetCalculator;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Helpers\IPUtils;

try {
    $v = new Validator();
    $v->validate($body, [
        'network' => 'required|string',
    ]);
    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate CIDR
    try {
        $parsed = IPUtils::parseCIDR($body['network']);
    } catch (\InvalidArgumentException $e) {
        Response::unprocessable('Invalid CIDR', ['network' => $e->getMessage()]);
    }

    $calc    = new SubnetCalculator();
    $cidr    = $body['network'];
    $version = $calc->detectVersion($cidr);
    $range   = $calc->getUsableRange($cidr);

    $result = [
        'network'          => $cidr,
        'ip_version'       => $version,
        'prefix_length'    => $calc->prefixLength($cidr),
        'first_usable_ip'  => $range['first'],
        'last_usable_ip'   => $range['last'],
        'total_addresses'  => $range['total'],
    ];

    // IPv4-only: include network + broadcast
    if ($version === 'ipv4') {
        $result['network_address']   = $parsed['network'];
        $result['broadcast_address'] = $parsed['broadcast'];
    }

    // IPv6: include /64 counting
    if ($version === 'ipv6') {
        $subprefixLen = (int) ($body['subprefix_len'] ?? 64);
        if ($subprefixLen >= $parsed['prefix'] && $subprefixLen <= 128) {
            try {
                $result['prefixes_of_' . $subprefixLen] = $calc->countIPv6Prefixes($cidr, $subprefixLen);
            } catch (\InvalidArgumentException) {
                // Skip if invalid subprefix
            }
        }
    }

    Response::json(['data' => $result]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Calculation failed: ' . $e->getMessage(), 500);
}
