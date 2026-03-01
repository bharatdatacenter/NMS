<?php

declare(strict_types=1);

namespace NMS\Vendors\VyOS;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\VendorAdapter;
use Predis\Client as RedisClient;

/**
 * VyOSAdapter — implements NetworkDeviceInterface for VyOS routers.
 *
 * VyOS REST API uses config paths (space-separated hierarchical nodes).
 * All data retrieval uses /retrieve or /show endpoints.
 * All modifications use /configure with set/delete operations.
 */
class VyOSAdapter extends VendorAdapter
{
    private VyOSAPI $api;
    private bool $connected = false;

    private const ALLOWED_COMMANDS = [
        'show interfaces',
        'show ip route',
        'show ip bgp summary',
        'ping',
        'traceroute',
    ];

    public function __construct(array $device, SecretsManagerInterface $secrets, RedisClient $redis)
    {
        parent::__construct($device, $redis);

        $vaultPath = $device['vault_path'] ?? "nms/devices/{$device['id']}";
        $apiKey    = $secrets->get("{$vaultPath}/api_key");

        $this->api = new VyOSAPI($device['ip_address'], $apiKey);
    }

    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool
    {
        try {
            $result = $this->call(fn() => $this->api->show('version'));
            $this->connected = !empty($result);
        } catch (\Exception) {
            $this->connected = false;
        }
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
            // VyOS: list interfaces then retrieve each interface's addresses
            $interfaces = $this->api->retrieve('interfaces ethernet', 'listNodes');
            $addresses  = [];

            if (!is_array($interfaces)) {
                $interfaces = [];
            }

            foreach ($interfaces as $iface) {
                $path = "interfaces ethernet {$iface} address";
                try {
                    $addrs = $this->api->retrieve($path, 'returnValues');
                    if (!is_array($addrs)) {
                        $addrs = $addrs !== null ? [$addrs] : [];
                    }
                    foreach ($addrs as $addr) {
                        $isIpv6 = str_contains($addr, ':');
                        if ($family === 'all'
                            || ($family === 'ipv4' && !$isIpv6)
                            || ($family === 'ipv6' && $isIpv6)) {
                            [$ip, $prefix] = explode('/', $addr . '/32', 2);
                            $addresses[] = [
                                'address'       => $ip,
                                'prefix_length' => (int)$prefix,
                                'cidr'          => $addr,
                                'interface'     => $iface,
                                'disabled'      => false,
                                'dynamic'       => false,
                            ];
                        }
                    }
                } catch (\Exception) {
                    // Interface may not have an address
                }
            }
            return $addresses;
        });
    }

    public function addIpAddress(string $ip, string $interface): bool
    {
        return $this->call(function () use ($ip, $interface): bool {
            return $this->api->set("interfaces ethernet {$interface} address", $ip);
        });
    }

    public function removeIpAddress(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            // Find which interface has this IP
            $interfaces = $this->api->retrieve('interfaces ethernet', 'listNodes') ?? [];
            foreach ((array)$interfaces as $iface) {
                try {
                    $addrs = (array)($this->api->retrieve("interfaces ethernet {$iface} address", 'returnValues') ?? []);
                    if (in_array($ip, $addrs, true) || in_array(explode('/', $ip)[0], array_map(fn($a) => explode('/', $a)[0], $addrs), true)) {
                        return $this->api->delete("interfaces ethernet {$iface} address {$ip}");
                    }
                } catch (\Exception) {}
            }
            return false;
        });
    }

    // ─── Routing ──────────────────────────────────────────────────────────────

    public function getRoutes(string $family = 'ipv4'): array
    {
        return $this->call(function () use ($family): array {
            $protocol = $family === 'ipv6' ? 'protocols static route6' : 'protocols static route';
            try {
                $routes = $this->api->retrieve($protocol, 'listNodes');
                if (!is_array($routes)) {
                    return [];
                }

                $result = [];
                foreach ($routes as $dest) {
                    try {
                        $nextHops = $this->api->retrieve("{$protocol} {$dest} next-hop", 'listNodes');
                        $nextHops = is_array($nextHops) ? $nextHops : [$nextHops];
                        foreach ((array)$nextHops as $gw) {
                            $result[] = [
                                'destination' => $dest,
                                'gateway'     => $gw,
                                'protocol'    => 'static',
                                'active'      => true,
                                'disabled'    => false,
                            ];
                        }
                    } catch (\Exception) {
                        $result[] = [
                            'destination' => $dest,
                            'gateway'     => null,
                            'protocol'    => 'static',
                            'active'      => true,
                            'disabled'    => false,
                        ];
                    }
                }
                return $result;
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function addStaticRoute(string $destination, string $gateway, ?string $interface = null): bool
    {
        return $this->call(function () use ($destination, $gateway, $interface): bool {
            $family = str_contains($destination, ':') ? 'route6' : 'route';
            return $this->api->set("protocols static {$family} {$destination} next-hop {$gateway}");
        });
    }

    public function removeRoute(string $destination): bool
    {
        return $this->call(function () use ($destination): bool {
            $family = str_contains($destination, ':') ? 'route6' : 'route';
            return $this->api->delete("protocols static {$family} {$destination}");
        });
    }

    // ─── Neighbor Table ───────────────────────────────────────────────────────

    public function getNeighborTable(string $protocol = 'arp'): array
    {
        return $this->call(function () use ($protocol): array {
            $command = $protocol === 'ndp' ? 'ipv6 neighbors' : 'arp';
            try {
                $raw = $this->api->show($command);
                $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
                return $this->parseArpOutput($output, $protocol);
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function addStaticNeighbor(string $ip, string $mac, string $interface): bool
    {
        return $this->call(function () use ($ip, $mac, $interface): bool {
            // VyOS static ARP
            return $this->api->set("protocols static arp {$ip} hwaddr", $mac);
        });
    }

    public function removeNeighbor(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            return $this->api->delete("protocols static arp {$ip}");
        });
    }

    // ─── Firewall ─────────────────────────────────────────────────────────────

    public function getFirewallRules(): array
    {
        return $this->call(function (): array {
            try {
                $ruleSets = $this->api->retrieve('firewall name', 'listNodes');
                $rules    = [];

                foreach ((array)$ruleSets as $setName) {
                    $ruleNums = $this->api->retrieve("firewall name {$setName} rule", 'listNodes') ?? [];
                    foreach ((array)$ruleNums as $ruleNum) {
                        $action = $this->api->retrieve("firewall name {$setName} rule {$ruleNum} action", 'returnValue');
                        $rules[] = [
                            'rule_set'    => $setName,
                            'rule_number' => (int)$ruleNum,
                            'action'      => $action ?? 'accept',
                            'source'      => $this->api->retrieve("firewall name {$setName} rule {$ruleNum} source network", 'returnValue'),
                            'destination' => $this->api->retrieve("firewall name {$setName} rule {$ruleNum} destination network", 'returnValue'),
                            'protocol'    => $this->api->retrieve("firewall name {$setName} rule {$ruleNum} protocol", 'returnValue'),
                            'disabled'    => false,
                        ];
                    }
                }
                return $rules;
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function addFirewallRule(array $rule): bool
    {
        return $this->call(function () use ($rule): bool {
            $setName = $rule['rule_set'] ?? 'NMS-RULES';
            $num     = $rule['rule_number'] ?? 100;
            $base    = "firewall name {$setName} rule {$num}";

            $this->api->set("{$base} action", $rule['action'] ?? 'accept');
            if (!empty($rule['source'])) {
                $this->api->set("{$base} source network", $rule['source']);
            }
            if (!empty($rule['destination'])) {
                $this->api->set("{$base} destination network", $rule['destination']);
            }
            if (!empty($rule['protocol'])) {
                $this->api->set("{$base} protocol", $rule['protocol']);
            }
            return true;
        });
    }

    public function removeFirewallRule(string $ruleId): bool
    {
        return $this->call(function () use ($ruleId): bool {
            // ruleId expected as "SetName/RuleNum"
            [$set, $num] = array_pad(explode('/', $ruleId, 2), 2, null);
            if ($set && $num) {
                return $this->api->delete("firewall name {$set} rule {$num}");
            }
            return false;
        });
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array
    {
        return $this->call(function (): array {
            $interfaces = [];

            foreach (['ethernet', 'loopback', 'tunnel', 'wireguard'] as $type) {
                try {
                    $nodes = $this->api->retrieve("interfaces {$type}", 'listNodes') ?? [];
                    foreach ((array)$nodes as $iface) {
                        $desc = $this->api->retrieve("interfaces {$type} {$iface} description", 'returnValue');
                        $interfaces[] = [
                            'name'        => $iface,
                            'type'        => $type,
                            'description' => $desc,
                            'disabled'    => false,
                            'running'     => true,
                        ];
                    }
                } catch (\Exception) {}
            }
            return $interfaces;
        });
    }

    public function getInterfaceStatus(string $interface): array
    {
        return $this->call(function () use ($interface): array {
            try {
                $raw    = $this->api->show("interfaces ethernet {$interface} detail");
                $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
                return [
                    'name'        => $interface,
                    'type'        => 'ethernet',
                    'running'     => str_contains($output, 'state UP'),
                    'output'      => $output,
                ];
            } catch (\Exception) {
                return ['name' => $interface, 'running' => false];
            }
        });
    }

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array
    {
        return $this->call(function (): array {
            $raw    = $this->api->show('version');
            $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
            return [
                'platform' => 'VyOS',
                'output'   => $output,
            ];
        });
    }

    public function getNeighborDiscovery(): array
    {
        return $this->call(function (): array {
            try {
                $raw = $this->api->show('lldp neighbors detail');
                return [['output' => is_array($raw) ? ($raw['output'] ?? '') : (string)$raw]];
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function backupConfig(): string
    {
        return $this->call(function (): string {
            $raw = $this->api->retrieve('', 'returnValues');
            return is_string($raw) ? $raw : json_encode([
                'timestamp' => date('c'),
                'config'    => $raw,
            ]);
        });
    }

    public function restoreConfig(string $config): bool
    {
        throw new \RuntimeException('Config restore via REST API is not supported for VyOS. Use the VyOS CLI or config management.');
    }

    // ─── Drift Detection ──────────────────────────────────────────────────────

    public function getConfigSections(): array
    {
        return $this->call(function (): array {
            return [
                'routes'     => $this->getRoutes('ipv4'),
                'firewall'   => $this->getFirewallRules(),
                'arp'        => $this->getNeighborTable('arp'),
                'interfaces' => $this->getInterfaces(),
            ];
        });
    }

    // ─── BGP / OSPF ───────────────────────────────────────────────────────────

    public function getBGPSessions(): array
    {
        return $this->call(function (): array {
            try {
                $raw    = $this->api->show('ip bgp summary');
                $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
                return $this->parseBGPSummary($output);
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function getBGPPrefixesForRange(string $cidr): array
    {
        return $this->call(function () use ($cidr): array {
            try {
                $raw    = $this->api->show("ip bgp {$cidr}");
                $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
                return [['cidr' => $cidr, 'output' => $output]];
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function getOSPFNeighbors(): array
    {
        return $this->call(function (): array {
            try {
                $raw    = $this->api->show('ip ospf neighbor');
                $output = is_array($raw) ? ($raw['output'] ?? '') : (string)$raw;
                return [['output' => $output]];
            } catch (\Exception) {
                return [];
            }
        });
    }

    // ─── HA Cluster ───────────────────────────────────────────────────────────

    public function getHAStatus(): array
    {
        // VyOS does not have built-in HA in the same sense; return VRRP info if configured
        return $this->call(function (): array {
            try {
                $groups = $this->api->retrieve('high-availability vrrp group', 'listNodes') ?? [];
                $members = [];
                foreach ((array)$groups as $group) {
                    $vrid      = $this->api->retrieve("high-availability vrrp group {$group} vrid", 'returnValue');
                    $priority  = $this->api->retrieve("high-availability vrrp group {$group} priority", 'returnValue');
                    $interface = $this->api->retrieve("high-availability vrrp group {$group} interface", 'returnValue');
                    $members[] = [
                        'group'     => $group,
                        'vrid'      => $vrid,
                        'priority'  => (int)($priority ?? 100),
                        'interface' => $interface,
                    ];
                }
                return ['type' => 'vrrp', 'members' => $members];
            } catch (\Exception) {
                return ['type' => 'none', 'members' => []];
            }
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
            $raw = $this->api->show($command);
            return is_string($raw) ? $raw : ($raw['output'] ?? json_encode($raw));
        });
    }

    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Parse plain-text ARP/NDP output into structured entries.
     * This is a best-effort text parser for `show arp` output.
     */
    private function parseArpOutput(string $output, string $protocol): array
    {
        if (empty($output)) {
            return [];
        }
        $entries = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            // Typical format: "? (192.168.1.1) at aa:bb:cc:dd:ee:ff [ether] on eth0"
            if (preg_match('/\(([^\)]+)\)\s+at\s+([0-9a-f:]+)/i', $line, $m)) {
                $entries[] = [
                    'ip'        => $m[1],
                    'mac'       => strtoupper($m[2]),
                    'interface' => null,
                    'protocol'  => $protocol,
                    'dynamic'   => true,
                ];
            }
        }
        return $entries;
    }

    /**
     * Parse BGP summary output into structured entries.
     */
    private function parseBGPSummary(string $output): array
    {
        if (empty($output)) {
            return [];
        }
        $sessions = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            // Match: "192.168.1.1    4      65001  ...  Established"
            if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\s+\d+\s+(\d+)\s+.*?(Established|Active|Idle|Connect)/i', $line, $m)) {
                $sessions[] = [
                    'remote_address' => $m[1],
                    'remote_as'      => $m[2],
                    'state'          => strtolower($m[3]),
                    'established'    => strtolower($m[3]) === 'established',
                ];
            }
        }
        return $sessions;
    }
}
