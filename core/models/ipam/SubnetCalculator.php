<?php

declare(strict_types=1);

namespace NMS\Core\Models\Ipam;

use NMS\Core\Helpers\IPUtils;

/**
 * SubnetCalculator
 *
 * CIDR math utilities for IPAM pool and subnet management.
 * Wraps IPUtils for pool-level calculations.
 */
class SubnetCalculator
{
    /**
     * Get the usable range for a CIDR.
     * IPv4: excludes network + broadcast (except /31, /32)
     * IPv6: all addresses usable (no broadcast)
     *
     * Returns ['first' => '...', 'last' => '...', 'total' => int|string]
     */
    public function getUsableRange(string $cidr): array
    {
        return IPUtils::getUsableRange($cidr);
    }

    public function getFirstUsable(string $cidr): string
    {
        return IPUtils::getFirstUsable($cidr);
    }

    public function getLastUsable(string $cidr): string
    {
        return IPUtils::getLastUsable($cidr);
    }

    /**
     * Total usable addresses in the subnet.
     * Returns int for IPv4 and small IPv6, string '2^N' for large IPv6 prefixes.
     */
    public function getTotalAddresses(string $cidr): int|string
    {
        return IPUtils::getTotalAddresses($cidr);
    }

    /**
     * For IPv6 pools, count the number of /64 allocations that fit within a larger prefix.
     * e.g. a /32 contains 2^32 /64 blocks.
     *
     * Returns int for small counts, '2^N' string for large ones.
     */
    public function countIPv6Prefixes(string $cidr, int $subprefixLen = 64): int|string
    {
        $parsed = IPUtils::parseCIDR($cidr);
        if ($parsed['version'] !== 6) {
            throw new \InvalidArgumentException('countIPv6Prefixes requires an IPv6 CIDR');
        }
        $diff = $subprefixLen - $parsed['prefix'];
        if ($diff < 0) {
            throw new \InvalidArgumentException(
                "Sub-prefix /{$subprefixLen} cannot be smaller than parent /{$parsed['prefix']}"
            );
        }
        if ($diff <= 30) {
            return (int) pow(2, $diff);
        }
        return '2^' . $diff;
    }

    /**
     * Check if a given IP is within a CIDR.
     */
    public function networkContains(string $cidr, string $ip): bool
    {
        return IPUtils::networkContains($cidr, $ip);
    }

    /**
     * Check if two CIDRs overlap.
     * Returns true if either CIDR's network address falls within the other.
     */
    public function cidrsOverlap(string $cidr1, string $cidr2): bool
    {
        $parsed1 = IPUtils::parseCIDR($cidr1);
        $parsed2 = IPUtils::parseCIDR($cidr2);

        if ($parsed1['version'] !== $parsed2['version']) {
            return false;
        }

        return IPUtils::networkContains($cidr1, $parsed2['network'])
            || IPUtils::networkContains($cidr2, $parsed1['network']);
    }

    /**
     * Calculate utilization percentage given used and total counts.
     */
    public function utilizationPercent(int $used, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }
        return round(($used / $total) * 100, 2);
    }

    /**
     * Return the prefix length from a CIDR string.
     */
    public function prefixLength(string $cidr): int
    {
        return IPUtils::parseCIDR($cidr)['prefix'];
    }

    /**
     * Detect IP version from a CIDR string. Returns 'ipv4' or 'ipv6'.
     */
    public function detectVersion(string $cidr): string
    {
        $parsed = IPUtils::parseCIDR($cidr);
        return $parsed['version'] === 4 ? 'ipv4' : 'ipv6';
    }
}
