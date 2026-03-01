<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

/**
 * IPv4 + IPv6 utilities.
 * Handles CIDR math, version detection, range calculations.
 */
class IPUtils
{
    /**
     * Parse a CIDR string into components.
     * Returns ['ip' => '...', 'prefix' => int, 'network' => '...', 'broadcast' => '...' (IPv4 only), 'version' => 4|6]
     */
    public static function parseCIDR(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new \InvalidArgumentException("Invalid CIDR: {$cidr}");
        }
        [$ip, $prefix] = explode('/', $cidr, 2);
        $prefix = (int)$prefix;
        $version = self::detectVersion($ip);

        if ($version === 4) {
            if ($prefix < 0 || $prefix > 32) {
                throw new \InvalidArgumentException("Invalid IPv4 prefix length: {$prefix}");
            }
            $ipLong    = ip2long($ip);
            $mask      = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
            $network   = long2ip($ipLong & $mask);
            $broadcast = long2ip(($ipLong & $mask) | (~$mask & 0xFFFFFFFF));
            return compact('ip', 'prefix', 'network', 'broadcast', 'version');
        }

        // IPv6
        if ($prefix < 0 || $prefix > 128) {
            throw new \InvalidArgumentException("Invalid IPv6 prefix length: {$prefix}");
        }
        $expanded = inet_pton($ip);
        if ($expanded === false) {
            throw new \InvalidArgumentException("Invalid IPv6 address: {$ip}");
        }
        $networkBin = self::ipv6NetworkBits($expanded, $prefix);
        $network    = inet_ntop($networkBin);

        return compact('ip', 'prefix', 'network', 'version');
    }

    /**
     * Detect IP version. Returns 4 or 6.
     * @throws \InvalidArgumentException if not a valid IP
     */
    public static function detectVersion(string $ip): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 4;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 6;
        }
        throw new \InvalidArgumentException("Not a valid IP address: {$ip}");
    }

    public static function isIPv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function isIPv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Get the usable IP range for a CIDR.
     * IPv4: excludes network + broadcast (except /31 and /32)
     * IPv6: all addresses are usable (no broadcast)
     *
     * Returns ['first' => '...', 'last' => '...', 'total' => int|string]
     */
    public static function getUsableRange(string $cidr): array
    {
        $parsed = self::parseCIDR($cidr);

        if ($parsed['version'] === 4) {
            return self::ipv4UsableRange($parsed);
        }
        return self::ipv6UsableRange($parsed);
    }

    public static function getFirstUsable(string $cidr): string
    {
        return self::getUsableRange($cidr)['first'];
    }

    public static function getLastUsable(string $cidr): string
    {
        return self::getUsableRange($cidr)['last'];
    }

    public static function getTotalAddresses(string $cidr): int|string
    {
        return self::getUsableRange($cidr)['total'];
    }

    /**
     * Check if an IP is within a given CIDR block.
     */
    public static function networkContains(string $cidr, string $ip): bool
    {
        $parsed = self::parseCIDR($cidr);
        $version = self::detectVersion($ip);

        if ($parsed['version'] !== $version) {
            return false;
        }

        if ($version === 4) {
            $mask     = $parsed['prefix'] === 0 ? 0 : (~0 << (32 - $parsed['prefix'])) & 0xFFFFFFFF;
            $network  = ip2long($parsed['network']);
            $ipLong   = ip2long($ip);
            return ($ipLong & $mask) === $network;
        }

        // IPv6
        $networkBin = inet_pton($parsed['network']);
        $ipBin      = inet_pton($ip);
        $prefixBytes = (int)floor($parsed['prefix'] / 8);
        $prefixBits  = $parsed['prefix'] % 8;

        if (substr($networkBin, 0, $prefixBytes) !== substr($ipBin, 0, $prefixBytes)) {
            return false;
        }
        if ($prefixBits > 0) {
            $mask = (0xFF << (8 - $prefixBits)) & 0xFF;
            return (ord($networkBin[$prefixBytes]) & $mask) === (ord($ipBin[$prefixBytes]) & $mask);
        }
        return true;
    }

    /**
     * Find the next available IP after the given one within a CIDR.
     * Returns null if no more addresses.
     */
    public static function nextAvailable(string $ip, string $cidr): ?string
    {
        $parsed = self::parseCIDR($cidr);

        if ($parsed['version'] === 4) {
            $next = long2ip(ip2long($ip) + 1);
            $last = self::getLastUsable($cidr);
            if (ip2long($next) > ip2long($last)) {
                return null;
            }
            return $next;
        }

        // IPv6
        $ipBin   = inet_pton($ip);
        $lastStr = self::getLastUsable($cidr);
        $lastBin = inet_pton($lastStr);

        $nextBin = self::ipv6Increment($ipBin);
        if ($nextBin === null || strcmp($nextBin, $lastBin) > 0) {
            return null;
        }
        return inet_ntop($nextBin);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private static function ipv4UsableRange(array $parsed): array
    {
        $prefix = $parsed['prefix'];

        if ($prefix === 32) {
            return ['first' => $parsed['ip'], 'last' => $parsed['ip'], 'total' => 1];
        }
        if ($prefix === 31) {
            $net  = long2ip(ip2long($parsed['network']));
            $last = long2ip(ip2long($parsed['network']) + 1);
            return ['first' => $net, 'last' => $last, 'total' => 2];
        }

        $first = long2ip(ip2long($parsed['network']) + 1);
        $last  = long2ip(ip2long($parsed['broadcast']) - 1);
        $total = pow(2, 32 - $prefix) - 2;

        return compact('first', 'last', 'total');
    }

    private static function ipv6UsableRange(array $parsed): array
    {
        $network = inet_pton($parsed['network']);
        $prefix  = $parsed['prefix'];

        // Calculate last address
        $lastBin = $network;
        $hostBits = 128 - $prefix;
        for ($i = 15; $i >= 0 && $hostBits > 0; $i--) {
            $bits = min($hostBits, 8);
            $lastBin[$i] = chr(ord($lastBin[$i]) | ((1 << $bits) - 1));
            $hostBits -= $bits;
        }

        // IPv6 total: 2^(128-prefix), can be astronomically large
        // For /64: 2^64 = ~1.8×10^19 — use string representation
        if ($prefix <= 64) {
            $total = '2^' . (128 - $prefix);
        } else {
            $total = (int)pow(2, 128 - $prefix);
        }

        return [
            'first' => inet_ntop($network),
            'last'  => inet_ntop($lastBin),
            'total' => $total,
        ];
    }

    private static function ipv6NetworkBits(string $ipBin, int $prefix): string
    {
        $result = $ipBin;
        $prefixBytes = (int)floor($prefix / 8);
        $prefixBits  = $prefix % 8;

        // Zero out bytes after prefix
        for ($i = $prefixBytes + ($prefixBits > 0 ? 1 : 0); $i < 16; $i++) {
            $result[$i] = "\x00";
        }
        // Mask partial byte
        if ($prefixBits > 0) {
            $mask = (0xFF << (8 - $prefixBits)) & 0xFF;
            $result[$prefixBytes] = chr(ord($ipBin[$prefixBytes]) & $mask);
        }
        return $result;
    }

    private static function ipv6Increment(string $ipBin): ?string
    {
        $result = $ipBin;
        for ($i = 15; $i >= 0; $i--) {
            $byte = ord($result[$i]);
            if ($byte < 255) {
                $result[$i] = chr($byte + 1);
                return $result;
            }
            $result[$i] = "\x00";
        }
        return null; // Overflow — was ::ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
    }
}
