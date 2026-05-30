<?php

declare(strict_types=1);

namespace NMS\Vendors\Cisco;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\VendorAdapter;
use Predis\Client as RedisClient;

/**
 * CiscoAdapter — implements NetworkDeviceInterface for Cisco IOS-XE via RESTCONF.
 *
 * All API calls are wrapped via parent::call() which applies RetryHandler +
 * CircuitBreaker and syncs circuit state to MongoDB on transitions.
 *
 * Credentials are loaded from Vault at construction time.
 */
class CiscoAdapter extends VendorAdapter
{
    private CiscoRESTCONF $api;
    private bool $connected = false;

    private const ALLOWED_COMMANDS = [
        'show interfaces',
        'ping',
        'traceroute',
    ];

    public function __construct(array $device, SecretsManagerInterface $secrets, RedisClient $redis)
    {
        parent::__construct($device, $redis);

        $vaultPath = $device['vault_path'] ?? "nms/devices/{$device['id']}";
        $username  = $secrets->get("{$vaultPath}/username");
        $password  = $secrets->get("{$vaultPath}/password");

        $this->api = new CiscoRESTCONF($device['ip_address'], $username, $password);
    }

    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool
    {
        $info = $this->call(fn() => $this->api->getSystemInfo());
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
            $interfaces = $this->api->getInterfaces();
            $addresses  = [];

            foreach ($interfaces as $iface) {
                $ipv4 = $iface['ietf-ip:ipv4']['address'] ?? [];
                $ipv6 = $iface['ietf-ip:ipv6']['address'] ?? [];

                if ($family === 'ipv4' || $family === 'all') {
                    foreach ($ipv4 as $addr) {
                        $addresses[] = [
                            'address'   => ($addr['ip'] ?? '') . '/' . ($addr['prefix-length'] ?? ''),
                            'interface' => $iface['name'] ?? '',
                            'family'    => 'ipv4',
                        ];
                    }
                }
                if ($family === 'ipv6' || $family === 'all') {
                    foreach ($ipv6 as $addr) {
                        $addresses[] = [
                            'address'   => ($addr['ip'] ?? '') . '/' . ($addr['prefix-length'] ?? ''),
                            'interface' => $iface['name'] ?? '',
                            'family'    => 'ipv6',
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
            $isIpv6 = str_contains($ip, ':');
            [$ipAddr, $prefix] = explode('/', $ip, 2) + ['', '32'];
            $path = "/ietf-interfaces:interfaces/interface={$interface}";
            $family = $isIpv6 ? 'ietf-ip:ipv6' : 'ietf-ip:ipv4';

            $this->api->patch($path, [
                $family => [
                    'address' => [
                        ['ip' => $ipAddr, 'prefix-length' => (int)$prefix],
                    ],
                ],
            ]);
            return true;
        });
    }

    public function removeIpAddress(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            $isIpv6 = str_contains($ip, ':');
            [$ipAddr] = explode('/', $ip, 2);
            $family = $isIpv6 ? 'ietf-ip:ipv6' : 'ietf-ip:ipv4';

            // Find which interface has this IP
            $ifaces = $this->api->getInterfaces();
            foreach ($ifaces as $iface) {
                $addrs = $iface[$family]['address'] ?? [];
                foreach ($addrs as $addr) {
                    if (($addr['ip'] ?? '') === $ipAddr) {
                        $ifName = $iface['name'];
                        $this->api->delete(
                            "/ietf-interfaces:interfaces/interface={$ifName}/{$family}/address={$ipAddr}"
                        );
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ─── Firewall (ACLs) ──────────────────────────────────────────────────────

    public function getFirewallRules(): array
    {
        return $this->call(function (): array {
            $acls  = $this->api->getACLs();
            $rules = [];

            $aclList = $acls['acls']['acl'] ?? [];
            foreach ($aclList as $acl) {
                $name = $acl['name'] ?? '';
                foreach ($acl['aces']['ace'] ?? [] as $ace) {
                    $rules[] = [
                        'id'       => $ace['name'] ?? null,
                        'acl'      => $name,
                        'action'   => $ace['actions']['forwarding'] ?? 'deny',
                        'protocol' => $ace['matches']['l3']['ipv4']['protocol'] ?? null,
                        'src'      => $ace['matches']['l3']['ipv4']['source-ipv4-network'] ?? null,
                        'dst'      => $ace['matches']['l3']['ipv4']['destination-ipv4-network'] ?? null,
                    ];
                }
            }
            return $rules;
        });
    }

    public function addFirewallRule(array $rule): bool
    {
        return $this->call(function () use ($rule): bool {
            $aclName = $rule['acl'] ?? 'NMS-MANAGED';
            $this->api->patch("/Cisco-IOS-XE-acl:acl/acls/acl={$aclName}/aces", [
                'ace' => [[
                    'name'    => $rule['id'] ?? uniqid('nms-'),
                    'actions' => ['forwarding' => $rule['action'] ?? 'permit'],
                ]],
            ]);
            return true;
        });
    }

    public function removeFirewallRule(string $ruleId): bool
    {
        return $this->call(function () use ($ruleId): bool {
            // Cisco ACE removal requires knowing the ACL name — encode as "aclName:aceName"
            [$aclName, $aceName] = explode(':', $ruleId, 2) + ['NMS-MANAGED', $ruleId];
            $this->api->delete("/Cisco-IOS-XE-acl:acl/acls/acl={$aclName}/aces/ace={$aceName}");
            return true;
        });
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->getInterfaces();
            return array_map(fn(array $iface) => [
                'name'       => $iface['name'] ?? '',
                'type'       => $iface['type'] ?? null,
                'enabled'    => $iface['enabled'] ?? true,
                'admin_up'   => $iface['admin-status'] ?? null,
                'oper_up'    => $iface['oper-status'] ?? null,
                'speed'      => $iface['speed'] ?? null,
                'mtu'        => $iface['mtu'] ?? null,
                'mac'        => $iface['phys-address'] ?? null,
            ], $raw);
        });
    }

    public function getInterfaceStatus(string $interface): array
    {
        return $this->call(function () use ($interface): array {
            $body = $this->api->get("/ietf-interfaces:interfaces/interface={$interface}");
            $iface = $body['ietf-interfaces:interface'][0] ?? $body['ietf-interfaces:interface'] ?? [];
            return [
                'name'    => $iface['name'] ?? $interface,
                'enabled' => $iface['enabled'] ?? true,
                'oper_up' => $iface['oper-status'] ?? null,
            ];
        });
    }

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->getSystemInfo();
            $hw  = $raw[0] ?? $raw;
            return [
                'model'    => $hw['device-inventory']['hw-description'] ?? null,
                'version'  => $hw['device-system-data']['software-version'] ?? null,
                'hostname' => $hw['device-system-data']['hostname'] ?? null,
                'uptime'   => $hw['device-system-data']['uptime'] ?? null,
            ];
        });
    }

    public function getNeighborDiscovery(): array
    {
        // Cisco CDP/LLDP neighbor discovery
        return $this->call(function (): array {
            try {
                $body  = $this->api->get('/Cisco-IOS-XE-cdp-oper:cdp-neighbor-details');
                $cdp   = $body['Cisco-IOS-XE-cdp-oper:cdp-neighbor-details']['cdp-neighbor-detail'] ?? [];
                return array_map(fn(array $n) => [
                    'device_id' => $n['device-name'] ?? null,
                    'interface' => $n['local-intf-name'] ?? null,
                    'remote_if' => $n['port-id'] ?? null,
                    'platform'  => $n['platform-name'] ?? null,
                ], $cdp);
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function backupConfig(): string
    {
        return $this->call(function (): string {
            $config = $this->api->getNativeConfig();
            return json_encode([
                'timestamp' => date('c'),
                'vendor'    => 'cisco',
                'config'    => $config,
            ]);
        });
    }

    public function restoreConfig(string $config): bool
    {
        return $this->call(function () use ($config): bool {
            $data = json_decode($config, true);
            if (empty($data['config'])) {
                throw new \InvalidArgumentException('Invalid Cisco config backup format');
            }
            $this->api->put('/Cisco-IOS-XE-native:native', $data['config']);
            return true;
        });
    }

    // ─── Drift Detection ──────────────────────────────────────────────────────

    public function getConfigSections(): array
    {
        return $this->call(function (): array {
            return [
                'firewall'   => $this->getFirewallRules(),
                'interfaces' => $this->getInterfaces(),
            ];
        });
    }

    // ─── HA ───────────────────────────────────────────────────────────────────

    public function getHAStatus(): array
    {
        // Cisco IOS-XE uses HSRP/VRRP
        return $this->call(function (): array {
            try {
                $body = $this->api->get('/Cisco-IOS-XE-vrrp-oper:vrrp-oper-data');
                $vrrp = $body['Cisco-IOS-XE-vrrp-oper:vrrp-oper-data']['vrrp-oper-interface'] ?? [];
                return [
                    'type'    => 'vrrp',
                    'members' => array_map(fn(array $e) => [
                        'interface' => $e['if-name'] ?? null,
                        'group'     => $e['vrrp-oper-group'][0]['group-id'] ?? null,
                        'state'     => $e['vrrp-oper-group'][0]['state'] ?? null,
                        'priority'  => $e['vrrp-oper-group'][0]['priority'] ?? null,
                    ], $vrrp),
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

        // For Cisco RESTCONF, map show commands to equivalent REST calls
        return $this->call(function () use ($command): string {
            $result = match (true) {
                str_starts_with($command, 'show interfaces') => $this->getInterfaces(),
                default => ['error' => "Command '{$command}' mapped but not directly executable via RESTCONF"],
            };
            return json_encode($result);
        });
    }

    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function prefixToMask(int $prefix): string
    {
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
        return long2ip($mask);
    }
}
