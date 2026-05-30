<?php

declare(strict_types=1);

namespace NMS\Vendors\Aruba;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use NMS\Vendors\VendorAdapter;
use Predis\Client as RedisClient;

/**
 * ArubaAdapter — implements NetworkDeviceInterface for Aruba CX via REST API.
 *
 * All API calls are wrapped via parent::call() which applies RetryHandler +
 * CircuitBreaker and syncs circuit state to MongoDB on transitions.
 *
 * Credentials are loaded from Vault at construction time.
 * Session management (login/logout) is handled by ArubaAPI.
 */
class ArubaAdapter extends VendorAdapter
{
    private ArubaAPI $api;
    private bool $connected = false;

    private const ALLOWED_COMMANDS = [
        'show interfaces',
        'show vlan',
        'ping',
        'traceroute',
    ];

    public function __construct(array $device, SecretsManagerInterface $secrets, RedisClient $redis)
    {
        parent::__construct($device, $redis);

        $vaultPath = $device['vault_path'] ?? "nms/devices/{$device['id']}";
        $username  = $secrets->get("{$vaultPath}/username");
        $password  = $secrets->get("{$vaultPath}/password");

        $this->api = new ArubaAPI($device['ip_address'], $username, $password);
    }

    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool
    {
        $this->call(function (): void {
            $this->api->login();
        });
        $this->connected = $this->api->isLoggedIn();
        return $this->connected;
    }

    public function disconnect(): void
    {
        try {
            $this->api->logout();
        } catch (\Throwable) {
            // Best-effort
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->api->isLoggedIn();
    }

    // ─── IP Management ────────────────────────────────────────────────────────

    public function getIpAddresses(string $family = 'ipv4'): array
    {
        return $this->call(function () use ($family): array {
            $ifaces    = $this->api->getInterfaces();
            $addresses = [];

            foreach ($ifaces as $ifName => $iface) {
                $ip4 = $iface['ip4_address'] ?? null;
                $ip6 = $iface['ip6_addresses'] ?? [];

                if (($family === 'ipv4' || $family === 'all') && $ip4) {
                    $addresses[] = [
                        'address'   => $ip4,
                        'interface' => $ifName,
                        'family'    => 'ipv4',
                    ];
                }
                if ($family === 'ipv6' || $family === 'all') {
                    foreach ($ip6 as $addr) {
                        $addresses[] = [
                            'address'   => $addr,
                            'interface' => $ifName,
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
            $field  = $isIpv6 ? 'ip6_addresses' : 'ip4_address';
            $this->api->put("/system/interfaces/{$interface}", [$field => $ip]);
            return true;
        });
    }

    public function removeIpAddress(string $ip): bool
    {
        return $this->call(function () use ($ip): bool {
            $ifaces = $this->api->getInterfaces();
            foreach ($ifaces as $ifName => $iface) {
                if (($iface['ip4_address'] ?? '') === $ip) {
                    $this->api->put("/system/interfaces/{$ifName}", ['ip4_address' => null]);
                    return true;
                }
                foreach ($iface['ip6_addresses'] ?? [] as $addr) {
                    if ($addr === $ip) {
                        // Remove from list
                        $remaining = array_filter(
                            $iface['ip6_addresses'],
                            fn($a) => $a !== $ip
                        );
                        $this->api->put("/system/interfaces/{$ifName}", ['ip6_addresses' => array_values($remaining)]);
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

            foreach ($acls as $aclName => $acl) {
                foreach ($acl['cfg_aces'] ?? [] as $seqNum => $ace) {
                    $rules[] = [
                        'id'       => "{$aclName}:{$seqNum}",
                        'acl'      => $aclName,
                        'sequence' => $seqNum,
                        'action'   => $ace['action'] ?? 'deny',
                        'protocol' => $ace['protocol'] ?? null,
                        'src'      => $ace['src_ip'] ?? null,
                        'dst'      => $ace['dst_ip'] ?? null,
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
            $seqNum  = $rule['sequence'] ?? 10;
            $this->api->put("/system/acls/{$aclName}/cfg_aces/{$seqNum}", [
                'action'   => $rule['action'] ?? 'permit',
                'protocol' => $rule['protocol'] ?? null,
                'src_ip'   => $rule['src'] ?? null,
                'dst_ip'   => $rule['dst'] ?? null,
            ]);
            return true;
        });
    }

    public function removeFirewallRule(string $ruleId): bool
    {
        return $this->call(function () use ($ruleId): bool {
            [$aclName, $seqNum] = explode(':', $ruleId, 2) + ['NMS-MANAGED', $ruleId];
            $this->api->delete("/system/acls/{$aclName}/cfg_aces/{$seqNum}");
            return true;
        });
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array
    {
        return $this->call(function (): array {
            $raw = $this->api->getInterfaces();
            $ifaces = [];
            foreach ($raw as $name => $iface) {
                $ifaces[] = [
                    'name'     => $name,
                    'type'     => $iface['type'] ?? null,
                    'enabled'  => $iface['admin_state'] ?? null,
                    'oper_up'  => $iface['link_state'] ?? null,
                    'mtu'      => $iface['mtu'] ?? null,
                    'mac'      => $iface['mac_addr'] ?? null,
                    'vlan'     => $iface['vlan_tag'] ?? null,
                    'speed'    => $iface['max_speed'] ?? null,
                ];
            }
            return $ifaces;
        });
    }

    public function getInterfaceStatus(string $interface): array
    {
        return $this->call(function () use ($interface): array {
            $iface = $this->api->getInterfaceDetail($interface);
            return [
                'name'    => $interface,
                'enabled' => $iface['admin_state'] ?? null,
                'oper_up' => $iface['link_state'] ?? null,
                'speed'   => $iface['max_speed'] ?? null,
            ];
        });
    }

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array
    {
        return $this->call(function (): array {
            $info = $this->api->getSystemInfo();
            return [
                'hostname' => $info['hostname'] ?? null,
                'model'    => $info['platform_name'] ?? null,
                'version'  => $info['software_version'] ?? null,
                'uptime'   => $info['uptime'] ?? null,
            ];
        });
    }

    public function getNeighborDiscovery(): array
    {
        // Aruba supports LLDP
        return $this->call(function (): array {
            try {
                $body = $this->api->get('/system/interfaces');
                // LLDP neighbors are typically available via a separate endpoint
                $body = $this->api->get('/system/lldp_neighbors');
                $nbrs = [];
                foreach ($body as $key => $nbr) {
                    $nbrs[] = [
                        'local_port'  => $nbr['local_port'] ?? null,
                        'remote_id'   => $nbr['chassis_id'] ?? null,
                        'remote_port' => $nbr['port_id'] ?? null,
                        'system'      => $nbr['system_name'] ?? null,
                    ];
                }
                return $nbrs;
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function backupConfig(): string
    {
        return $this->call(function (): string {
            $system  = $this->api->getSystemInfo();
            $ifaces  = $this->api->getInterfaces();
            $vlans   = $this->api->getVLANs();
            return json_encode([
                'timestamp'  => date('c'),
                'vendor'     => 'aruba',
                'system'     => $system,
                'interfaces' => $ifaces,
                'vlans'      => $vlans,
            ]);
        });
    }

    public function restoreConfig(string $config): bool
    {
        return $this->call(function () use ($config): bool {
            $data = json_decode($config, true);
            if (empty($data)) {
                throw new \InvalidArgumentException('Invalid Aruba config backup format');
            }
            // Restore system settings — VLANs, interfaces
            if (!empty($data['vlans'])) {
                foreach ($data['vlans'] as $id => $vlan) {
                    $this->api->put("/system/vlans/{$id}", $vlan);
                }
            }
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
        return $this->call(function (): array {
            try {
                $body = $this->api->get('/system/vsx_info');
                return [
                    'type'    => 'vsx',
                    'members' => [
                        [
                            'role'   => $body['vsx_peer_role'] ?? null,
                            'state'  => $body['vsx_peer_state'] ?? null,
                            'config_sync' => $body['vsx_config_sync_disable'] ?? null,
                        ],
                    ],
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
            $result = match (true) {
                str_starts_with($command, 'show interfaces') => $this->getInterfaces(),
                str_starts_with($command, 'show vlan')       => $this->api->getVLANs(),
                default => ['info' => "Command '{$command}' not directly mappable to REST endpoint"],
            };
            return json_encode($result);
        });
    }

    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

}
