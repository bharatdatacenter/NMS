<?php

declare(strict_types=1);

namespace NMS\Vendors\FortiGate;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\VendorAdapter;
use Predis\Client as RedisClient;

/**
 * FortiGateAdapter — implements NetworkDeviceInterface for Fortinet FortiGate.
 *
 * Supports IPv4 policies (/cmdb/firewall/policy) and IPv6 policies (/cmdb/firewall/policy6).
 * VIPs are always IPv4-only (NAT not applicable for IPv6 — direct address match).
 * HA status via /monitor/system/ha-peer.
 * BGP via /monitor/router/bgp/neighbors.
 */
class FortiGateAdapter extends VendorAdapter
{
    private FortiGateAPI $api;
    private FortiGateParser $parser;
    private bool $connected = false;

    private const ALLOWED_COMMANDS = [
        'get system status',
        'get router info routing-table all',
        'diagnose sys top',
        'execute ping',
        'execute traceroute',
        'get system performance status',
    ];

    public function __construct(array $device, SecretsManagerInterface $secrets, RedisClient $redis)
    {
        parent::__construct($device, $redis);

        $vaultPath = $device['vault_path'] ?? "nms/devices/{$device['id']}";
        $token     = $secrets->get("{$vaultPath}/api_token");

        $this->api    = new FortiGateAPI($device['ip_address'], $token);
        $this->parser = new FortiGateParser();
    }

    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool
    {
        $info = $this->call(fn() => $this->api->monitor('/system/status'));
        $this->connected = !empty($info);
        return $this->connected;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ─── IP Management ────────────────────────────────────────────────────────

    public function getIpAddresses(string $family = 'ipv4'): array
    {
        return $this->call(function () use ($family): array {
            $interfaces = $this->api->get('/cmdb/system/interface');
            $parsed = $this->parser->parseInterfaces($interfaces);

            // Extract IP addresses from interfaces
            $addresses = [];
            foreach ($parsed as $iface) {
                if (!empty($iface['ip'])) {
                    [$ip, $mask] = explode(' ', $iface['ip'] . ' 255.255.255.255', 2);
                    $prefix = $this->maskToPrefix($mask);
                    $isIpv6 = str_contains($ip, ':');

                    if ($family === 'all'
                        || ($family === 'ipv4' && !$isIpv6)
                        || ($family === 'ipv6' && $isIpv6)) {
                        $addresses[] = [
                            'address'       => $ip,
                            'prefix_length' => $prefix,
                            'cidr'          => "{$ip}/{$prefix}",
                            'interface'     => $iface['name'],
                            'disabled'      => $iface['disabled'],
                        ];
                    }
                }
            }
            return $addresses;
        });
    }

    public function addIpAddress(string $ip, string $interface): bool
    {
        return $this->call(function () use ($ip, $interface): bool {
            // FortiGate: update interface IP
            $this->api->put("/cmdb/system/interface/{$interface}", [
                'ip' => $ip,
            ]);
            return true;
        });
    }

    public function removeIpAddress(string $ip): bool
    {
        // FortiGate does not support removing IPs directly; the interface must be updated
        throw new \RuntimeException('Remove IP not directly supported on FortiGate. Update the interface configuration.');
    }

    // ─── Routing ──────────────────────────────────────────────────────────────

    public function getRoutes(string $family = 'ipv4'): array
    {
        return $this->call(function () use ($family): array {
            $raw = $this->api->get('/cmdb/router/static');
            return $this->parser->parseRoutes($raw);
        });
    }

    public function addStaticRoute(string $destination, string $gateway, ?string $interface = null): bool
    {
        return $this->call(function () use ($destination, $gateway, $interface): bool {
            [$dest, $mask] = $this->cidrToNetmask($destination);
            $data = [
                'dst'     => $dest,
                'dst-mask'=> $mask,
                'gateway' => $gateway,
            ];
            if ($interface !== null) {
                $data['device'] = $interface;
            }
            $this->api->post('/cmdb/router/static', $data);
            return true;
        });
    }

    public function removeRoute(string $destination): bool
    {
        return $this->call(function () use ($destination): bool {
            $routes = $this->api->get('/cmdb/router/static');
            foreach ($routes as $route) {
                $routeDest = ($route['dst'] ?? '') . '/' . $this->maskToPrefix($route['dst-mask'] ?? '0.0.0.0');
                if ($routeDest === $destination) {
                    $seqNum = $route['seq-num'] ?? null;
                    if ($seqNum !== null) {
                        $this->api->delete("/cmdb/router/static/{$seqNum}");
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ─── Neighbor Table ───────────────────────────────────────────────────────

    public function getNeighborTable(string $protocol = 'arp'): array
    {
        return $this->call(function () use ($protocol): array {
            // FortiGate ARP via monitor endpoint
            try {
                $endpoint = $protocol === 'ndp' ? '/system/arp-table' : '/system/arp-table';
                $raw = $this->api->monitor($endpoint);
                $results = $raw['results'] ?? $raw;
                return array_map(function (array $entry): array {
                    return [
                        'ip'        => $entry['ip'] ?? null,
                        'mac'       => strtoupper($entry['mac'] ?? ''),
                        'interface' => $entry['interface'] ?? null,
                        'dynamic'   => true,
                    ];
                }, is_array($results) ? $results : []);
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function addStaticNeighbor(string $ip, string $mac, string $interface): bool
    {
        // FortiGate uses static ARP entries through configuration
        return $this->call(function () use ($ip, $mac, $interface): bool {
            $this->api->post('/cmdb/system/arp-table', [
                'ip'        => $ip,
                'mac'       => strtolower($mac),
                'interface' => $interface,
            ]);
            return true;
        });
    }

    public function removeNeighbor(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            $entries = $this->api->get('/cmdb/system/arp-table');
            foreach ($entries as $entry) {
                if (($entry['ip'] ?? '') === $ip) {
                    $id = $entry['seq-num'] ?? $entry['id'] ?? null;
                    if ($id !== null) {
                        $this->api->delete("/cmdb/system/arp-table/{$id}");
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ─── Firewall ─────────────────────────────────────────────────────────────

    /**
     * Returns both IPv4 (policy) and IPv6 (policy6) rules.
     */
    public function getFirewallRules(): array
    {
        return $this->call(function (): array {
            $ipv4Raw = $this->api->get('/cmdb/firewall/policy');
            $ipv4    = $this->parser->parseFirewallPolicies($ipv4Raw, 'ipv4');

            try {
                $ipv6Raw = $this->api->get('/cmdb/firewall/policy6');
                $ipv6    = $this->parser->parseFirewallPolicies($ipv6Raw, 'ipv6');
            } catch (\Exception) {
                $ipv6 = [];
            }

            return array_merge($ipv4, $ipv6);
        });
    }

    public function addFirewallRule(array $rule): bool
    {
        return $this->call(function () use ($rule): bool {
            $ipVersion = $rule['ip_version'] ?? 'ipv4';
            $endpoint  = $ipVersion === 'ipv6' ? '/cmdb/firewall/policy6' : '/cmdb/firewall/policy';

            $data = [
                'name'      => $rule['name'] ?? null,
                'srcintf'   => array_map(fn($n) => ['name' => $n], $rule['src_interfaces'] ?? ['any']),
                'dstintf'   => array_map(fn($n) => ['name' => $n], $rule['dst_interfaces'] ?? ['any']),
                'srcaddr'   => array_map(fn($n) => ['name' => $n], $rule['src_addresses'] ?? ['all']),
                'dstaddr'   => array_map(fn($n) => ['name' => $n], $rule['dst_addresses'] ?? ['all']),
                'service'   => array_map(fn($n) => ['name' => $n], $rule['services'] ?? ['ALL']),
                'action'    => $rule['action'] ?? 'accept',
                'status'    => 'enable',
                'logtraffic'=> $rule['log_traffic'] ?? 'all',
                'comments'  => $rule['comments'] ?? '',
            ];

            $this->api->post($endpoint, $data);
            return true;
        });
    }

    public function removeFirewallRule(string $ruleId): bool
    {
        return $this->call(function () use ($ruleId): bool {
            // Try IPv4 first, then IPv6
            try {
                $this->api->delete("/cmdb/firewall/policy/{$ruleId}");
                return true;
            } catch (\Exception) {
                $this->api->delete("/cmdb/firewall/policy6/{$ruleId}");
                return true;
            }
        });
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->get('/cmdb/system/interface');
            return $this->parser->parseInterfaces($raw);
        });
    }

    public function getInterfaceStatus(string $interface): array
    {
        return $this->call(function () use ($interface): array {
            $raw = $this->api->get("/cmdb/system/interface/{$interface}");
            $parsed = $this->parser->parseInterfaces(is_array($raw) && isset($raw[0]) ? $raw : [$raw]);
            return $parsed[0] ?? [];
        });
    }

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->monitor('/system/status');
            return $this->parser->parseSystemInfo($raw);
        });
    }

    public function getNeighborDiscovery(): array
    {
        return $this->call(function (): array {
            try {
                $raw = $this->api->monitor('/system/lldp/neighbors-summary');
                $results = $raw['results'] ?? $raw;
                return array_map(function (array $entry): array {
                    return [
                        'interface'      => $entry['local_port'] ?? null,
                        'remote_id'      => $entry['chassis_id'] ?? null,
                        'remote_name'    => $entry['system_name'] ?? null,
                        'remote_port'    => $entry['port_id'] ?? null,
                        'remote_address' => $entry['management_address'] ?? null,
                    ];
                }, is_array($results) ? $results : []);
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function backupConfig(): string
    {
        return $this->call(function (): string {
            // FortiGate config backup via /monitor/system/config
            try {
                $raw = $this->api->monitor('/system/config/backup', ['scope' => 'global', 'vdom' => 'root']);
                return is_string($raw) ? $raw : json_encode($raw);
            } catch (\Exception) {
                // Collect key config sections as fallback
                return json_encode([
                    'timestamp' => date('c'),
                    'policies'  => $this->api->get('/cmdb/firewall/policy'),
                    'routes'    => $this->api->get('/cmdb/router/static'),
                    'addresses' => $this->api->get('/cmdb/firewall/address'),
                ]);
            }
        });
    }

    public function restoreConfig(string $config): bool
    {
        throw new \RuntimeException('Config restore via REST API is not supported for FortiGate. Use the management console or TFTP.');
    }

    // ─── Drift Detection ──────────────────────────────────────────────────────

    public function getConfigSections(): array
    {
        return $this->call(function (): array {
            $routes    = $this->api->get('/cmdb/router/static');
            $policies  = $this->api->get('/cmdb/firewall/policy');
            $arp       = [];
            try {
                $arpRaw = $this->api->monitor('/system/arp-table');
                $arp    = $arpRaw['results'] ?? [];
            } catch (\Exception) {}
            $ifaces = $this->api->get('/cmdb/system/interface');

            return [
                'routes'     => $this->parser->parseRoutes($routes),
                'firewall'   => $this->parser->parseFirewallPolicies($policies, 'ipv4'),
                'arp'        => $arp,
                'interfaces' => $this->parser->parseInterfaces($ifaces),
            ];
        });
    }

    // ─── BGP / OSPF ───────────────────────────────────────────────────────────

    public function getBGPSessions(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->monitor('/router/bgp/neighbors');
            return $this->parser->parseBGPSessions($raw);
        });
    }

    public function getBGPPrefixesForRange(string $cidr): array
    {
        return $this->call(function () use ($cidr): array {
            // Targeted query: fetch routes and filter by CIDR overlap
            $raw = $this->api->monitor('/router/ipv4');
            $routes = $raw['results'] ?? [];
            return array_values(array_filter($routes, function (array $route) use ($cidr): bool {
                $dest = $route['ip_mask'] ?? $route['dst'] ?? '';
                return $this->cidrOverlaps($dest, $cidr);
            }));
        });
    }

    public function getOSPFNeighbors(): array
    {
        return $this->call(function (): array {
            try {
                $raw = $this->api->monitor('/router/ospf/neighbors');
                $results = $raw['results'] ?? [];
                return array_map(function (array $entry): array {
                    return [
                        'neighbor_id' => $entry['neighbor_ip'] ?? null,
                        'state'       => $entry['state'] ?? null,
                        'interface'   => $entry['local_intf'] ?? null,
                    ];
                }, $results);
            } catch (\Exception) {
                return [];
            }
        });
    }

    // ─── HA Status ────────────────────────────────────────────────────────────

    public function getHAStatus(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->monitor('/system/ha-peer');
            return $this->parser->parseHAStatus($raw);
        });
    }

    // ─── Safe Command Execution ───────────────────────────────────────────────

    public function executeCommand(string $command): string
    {
        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            throw new \InvalidArgumentException(
                "Command not in allowlist: {$command}. Allowed: " . implode(', ', self::ALLOWED_COMMANDS)
            );
        }

        return $this->call(function () use ($command): string {
            $result = match (true) {
                $command === 'get system status'
                    => $this->api->monitor('/system/status'),
                $command === 'get router info routing-table all'
                    => $this->api->monitor('/router/ipv4'),
                $command === 'get system performance status'
                    => $this->api->monitor('/system/performance/status'),
                default
                    => ['command' => $command, 'note' => 'CLI command simulation — use FortiGate console for full execution'],
            };
            return is_string($result) ? $result : json_encode($result);
        });
    }

    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Convert a subnet mask string to CIDR prefix length.
     * e.g. "255.255.255.0" → 24
     */
    private function maskToPrefix(string $mask): int
    {
        if (is_numeric($mask)) {
            return (int)$mask;
        }
        $long = ip2long($mask);
        if ($long === false) {
            return 0;
        }
        return strlen(rtrim(sprintf('%032b', $long), '0'));
    }

    /**
     * Convert a CIDR string to [network, netmask] pair.
     * e.g. "10.0.0.0/8" → ["10.0.0.0", "255.0.0.0"]
     */
    private function cidrToNetmask(string $cidr): array
    {
        [$network, $prefix] = explode('/', $cidr . '/32', 2);
        $prefix  = (int)$prefix;
        $long    = $prefix > 0 ? (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF : 0;
        return [$network, long2ip($long)];
    }

    /**
     * Check if two CIDR ranges overlap.
     */
    private function cidrOverlaps(string $a, string $b): bool
    {
        try {
            [$aIp, $aPfx] = explode('/', $a . '/32', 2);
            [$bIp, $bPfx] = explode('/', $b . '/32', 2);
            $aNet  = ip2long($aIp);
            $bNet  = ip2long($bIp);
            if ($aNet === false || $bNet === false) {
                return false;
            }
            $aMask = (int)$aPfx > 0 ? (0xFFFFFFFF << (32 - (int)$aPfx)) & 0xFFFFFFFF : 0;
            $bMask = (int)$bPfx > 0 ? (0xFFFFFFFF << (32 - (int)$bPfx)) & 0xFFFFFFFF : 0;
            return ($aNet & $bMask) === ($bNet & $bMask)
                || ($bNet & $aMask) === ($aNet & $aMask);
        } catch (\Throwable) {
            return false;
        }
    }
}
