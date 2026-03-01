<?php

declare(strict_types=1);

namespace NMS\Vendors\MikroTik;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\VendorAdapter;
use Predis\Client as RedisClient;

/**
 * MikroTikAdapter — implements NetworkDeviceInterface for MikroTik RouterOS.
 *
 * All API calls are wrapped via parent::call() which applies RetryHandler +
 * CircuitBreaker and syncs circuit state to MongoDB on transitions.
 *
 * Credentials are loaded from Vault at construction time using the device's
 * vault_path (e.g. "nms/devices/{id}"). Never stored in MongoDB.
 */
class MikroTikAdapter extends VendorAdapter
{
    private MikroTikAPI $api;
    private MikroTikParser $parser;
    private bool $connected = false;

    private const ALLOWED_COMMANDS = [
        'ping',
        'traceroute',
        '/tool/torch',
        '/system/resource/print',
        '/interface/print',
    ];

    public function __construct(array $device, SecretsManagerInterface $secrets, RedisClient $redis)
    {
        parent::__construct($device, $redis);

        $vaultPath = $device['vault_path'] ?? "nms/devices/{$device['id']}";
        $username  = $secrets->get("{$vaultPath}/username");
        $password  = $secrets->get("{$vaultPath}/password");

        $this->api    = new MikroTikAPI($device['ip_address'], $username, $password);
        $this->parser = new MikroTikParser();
    }

    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool
    {
        $info = $this->call(fn() => $this->api->get('/system/identity'));
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
            if ($family === 'ipv6') {
                $raw = $this->api->get('/ipv6/address');
            } elseif ($family === 'all') {
                $raw4 = $this->api->get('/ip/address');
                $raw6 = $this->api->get('/ipv6/address');
                return array_merge(
                    $this->parser->parseIpAddresses($raw4),
                    $this->parser->parseIpAddresses($raw6)
                );
            } else {
                $raw = $this->api->get('/ip/address');
            }
            return $this->parser->parseIpAddresses($raw);
        });
    }

    public function addIpAddress(string $ip, string $interface): bool
    {
        return $this->call(function () use ($ip, $interface): bool {
            // Determine if IPv6
            $endpoint = str_contains($ip, ':') ? '/ipv6/address' : '/ip/address';
            $this->api->put($endpoint, [
                'address'   => $ip,
                'interface' => $interface,
            ]);
            return true;
        });
    }

    public function removeIpAddress(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            $endpoint = str_contains($ip, ':') ? '/ipv6/address' : '/ip/address';
            // Find the .id for this IP
            $entries = $this->api->get($endpoint);
            foreach ($entries as $entry) {
                $entryIp = explode('/', $entry['address'] ?? '')[0];
                if ($entryIp === $ip) {
                    $id = $entry['.id'] ?? null;
                    if ($id) {
                        $this->api->delete("{$endpoint}/{$id}");
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ─── Routing ──────────────────────────────────────────────────────────────

    public function getRoutes(string $family = 'ipv4'): array
    {
        return $this->call(function () use ($family): array {
            $endpoint = $family === 'ipv6' ? '/ipv6/route' : '/ip/route';
            $raw = $this->api->get($endpoint);
            return $this->parser->parseRoutes($raw);
        });
    }

    public function addStaticRoute(string $destination, string $gateway, ?string $interface = null): bool
    {
        return $this->call(function () use ($destination, $gateway, $interface): bool {
            $endpoint = str_contains($destination, ':') ? '/ipv6/route' : '/ip/route';
            $data = [
                'dst-address' => $destination,
                'gateway'     => $gateway,
            ];
            $this->api->put($endpoint, $data);
            return true;
        });
    }

    public function removeRoute(string $destination): bool
    {
        return $this->call(function () use ($destination): bool {
            $endpoint = str_contains($destination, ':') ? '/ipv6/route' : '/ip/route';
            $entries = $this->api->get($endpoint);
            foreach ($entries as $entry) {
                if (($entry['dst-address'] ?? '') === $destination) {
                    $id = $entry['.id'] ?? null;
                    if ($id) {
                        $this->api->delete("{$endpoint}/{$id}");
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
            $endpoint = $protocol === 'ndp' ? '/ipv6/neighbor' : '/ip/arp';
            $raw = $this->api->get($endpoint);
            return $this->parser->parseNeighborTable($raw);
        });
    }

    public function addStaticNeighbor(string $ip, string $mac, string $interface): bool
    {
        return $this->call(function () use ($ip, $mac, $interface): bool {
            $endpoint = str_contains($ip, ':') ? '/ipv6/neighbor' : '/ip/arp';
            $this->api->put($endpoint, [
                'address'     => $ip,
                'mac-address' => $mac,
                'interface'   => $interface,
            ]);
            return true;
        });
    }

    public function removeNeighbor(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            $endpoint = str_contains($ip, ':') ? '/ipv6/neighbor' : '/ip/arp';
            $entries = $this->api->get($endpoint);
            foreach ($entries as $entry) {
                if (($entry['address'] ?? '') === $ip) {
                    $id = $entry['.id'] ?? null;
                    if ($id) {
                        $this->api->delete("{$endpoint}/{$id}");
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ─── Firewall ─────────────────────────────────────────────────────────────

    public function getFirewallRules(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->get('/ip/firewall/filter');
            return $this->parser->parseFirewallRules($raw);
        });
    }

    public function addFirewallRule(array $rule): bool
    {
        return $this->call(function () use ($rule): bool {
            // Map normalized fields back to MikroTik format
            $data = array_filter([
                'chain'             => $rule['chain'] ?? 'forward',
                'action'            => $rule['action'] ?? 'accept',
                'src-address'       => $rule['src_address'] ?? null,
                'dst-address'       => $rule['dst_address'] ?? null,
                'protocol'          => $rule['protocol'] ?? null,
                'src-port'          => $rule['src_port'] ?? null,
                'dst-port'          => $rule['dst_port'] ?? null,
                'in-interface'      => $rule['in_interface'] ?? null,
                'out-interface'     => $rule['out_interface'] ?? null,
                'comment'           => $rule['comment'] ?? null,
            ], fn($v) => $v !== null);

            $this->api->put('/ip/firewall/filter', $data);
            return true;
        });
    }

    public function removeFirewallRule(string $ruleId): bool
    {
        return $this->call(function () use ($ruleId): bool {
            $this->api->delete("/ip/firewall/filter/{$ruleId}");
            return true;
        });
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->get('/interface');
            return $this->parser->parseInterfaces($raw);
        });
    }

    public function getInterfaceStatus(string $interface): array
    {
        return $this->call(function () use ($interface): array {
            $entries = $this->api->get('/interface', ['.id' => $interface]);
            foreach ($entries as $entry) {
                if (($entry['name'] ?? '') === $interface) {
                    return $this->parser->parseInterfaces([$entry])[0] ?? [];
                }
            }
            return [];
        });
    }

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array
    {
        return $this->call(function (): array {
            $resource = $this->api->get('/system/resource');
            $identity = $this->api->get('/system/identity');
            $info = $this->parser->parseSystemInfo(is_array($resource) && !isset($resource[0]) ? $resource : ($resource[0] ?? []));
            $info['identity'] = $identity['name'] ?? null;
            return $info;
        });
    }

    public function getNeighborDiscovery(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->get('/ip/neighbor');
            return $this->parser->parseNeighborDiscovery($raw);
        });
    }

    public function backupConfig(): string
    {
        return $this->call(function (): string {
            // Export running config as text — MikroTik export command via REST
            // Uses /export endpoint or system/export
            try {
                $result = $this->api->post('/export');
                return is_string($result) ? $result : json_encode($result);
            } catch (\Exception) {
                // Fallback: collect key config sections
                $routes    = json_encode($this->api->get('/ip/route'));
                $addresses = json_encode($this->api->get('/ip/address'));
                $firewall  = json_encode($this->api->get('/ip/firewall/filter'));
                return json_encode([
                    'timestamp' => date('c'),
                    'routes'    => json_decode($routes, true),
                    'addresses' => json_decode($addresses, true),
                    'firewall'  => json_decode($firewall, true),
                ]);
            }
        });
    }

    public function restoreConfig(string $config): bool
    {
        // Config restore on MikroTik requires file upload — not supported via REST
        throw new \RuntimeException('Config restore via REST API is not supported for MikroTik. Use SSH or WinBox.');
    }

    // ─── Drift Detection ──────────────────────────────────────────────────────

    public function getConfigSections(): array
    {
        return $this->call(function (): array {
            $routes    = $this->api->get('/ip/route');
            $firewall  = $this->api->get('/ip/firewall/filter');
            $arp       = $this->api->get('/ip/arp');
            $ifaces    = $this->api->get('/interface');

            return [
                'routes'     => $this->parser->parseRoutes($routes),
                'firewall'   => $this->parser->parseFirewallRules($firewall),
                'arp'        => $this->parser->parseNeighborTable($arp),
                'interfaces' => $this->parser->parseInterfaces($ifaces),
            ];
        });
    }

    // ─── BGP / OSPF ───────────────────────────────────────────────────────────

    public function getBGPSessions(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->get('/routing/bgp/peer');
            return $this->parser->parseBGPSessions($raw);
        });
    }

    public function getBGPPrefixesForRange(string $cidr): array
    {
        return $this->call(function () use ($cidr): array {
            // MikroTik: query routing table for prefixes overlapping the CIDR
            $routes = $this->api->get('/ip/route');
            $parsed = $this->parser->parseRoutes($routes);

            // Filter to routes whose destination overlaps $cidr
            return array_values(array_filter($parsed, function (array $route) use ($cidr): bool {
                return $this->routeOverlapsCidr($route['destination'] ?? '', $cidr);
            }));
        });
    }

    public function getOSPFNeighbors(): array
    {
        return $this->call(function (): array {
            try {
                $raw = $this->api->get('/routing/ospf/neighbor');
                return array_map(function (array $entry): array {
                    return [
                        'id'        => $entry['.id'] ?? null,
                        'address'   => $entry['address'] ?? null,
                        'interface' => $entry['interface'] ?? null,
                        'state'     => $entry['state'] ?? null,
                        'priority'  => (int)($entry['priority'] ?? 0),
                        'router_id' => $entry['router-id'] ?? null,
                    ];
                }, $raw);
            } catch (\Exception) {
                return []; // OSPF may not be configured
            }
        });
    }

    // ─── HA Cluster ───────────────────────────────────────────────────────────

    public function getHAStatus(): array
    {
        return $this->call(function (): array {
            try {
                $raw = $this->api->get('/interface/vrrp');
                return [
                    'type'    => 'vrrp',
                    'members' => array_map(fn(array $e) => [
                        'interface' => $e['interface'] ?? null,
                        'master'    => $this->parser->parseBool($e['master'] ?? 'false'),
                        'vrid'      => (int)($e['vrid'] ?? 0),
                        'priority'  => (int)($e['priority'] ?? 100),
                        'running'   => $this->parser->parseBool($e['running'] ?? 'false'),
                    ], $raw),
                ];
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
            // Map command to REST endpoint equivalent
            $result = match (true) {
                str_starts_with($command, '/system/resource/print') => $this->api->get('/system/resource'),
                str_starts_with($command, '/interface/print')       => $this->api->get('/interface'),
                default => $this->api->post('/console', ['command' => $command]),
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
     * Check if a route destination overlaps with a given CIDR.
     * Simplified check — covers exact match and subnet containment.
     */
    private function routeOverlapsCidr(string $destination, string $cidr): bool
    {
        if (empty($destination) || empty($cidr)) {
            return false;
        }
        try {
            [$destIp, $destPfx] = $this->parser->splitCidr($destination);
            [$cidrIp, $cidrPfx] = $this->parser->splitCidr($cidr);

            $destNet  = ip2long($destIp);
            $cidrNet  = ip2long($cidrIp);
            if ($destNet === false || $cidrNet === false) {
                return false; // IPv6 — skip for now
            }

            $destMask = $destPfx > 0 ? (0xFFFFFFFF << (32 - $destPfx)) & 0xFFFFFFFF : 0;
            $cidrMask = $cidrPfx > 0 ? (0xFFFFFFFF << (32 - $cidrPfx)) & 0xFFFFFFFF : 0;

            // Overlap if either network contains the other's starting address
            return ($destNet & $cidrMask) === ($cidrNet & $cidrMask)
                || ($cidrNet & $destMask) === ($destNet & $destMask);
        } catch (\Throwable) {
            return false;
        }
    }
}
