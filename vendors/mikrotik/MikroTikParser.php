<?php

declare(strict_types=1);

namespace NMS\Vendors\MikroTik;

/**
 * MikroTikParser — normalizes raw MikroTik REST API responses into typed arrays.
 *
 * MikroTik uses dash-separated field names (".id", "dst-address", "actual-interface")
 * and boolean strings ("true"/"false"). This parser produces clean, uniform output.
 */
class MikroTikParser
{
    // ─── IP Addresses ─────────────────────────────────────────────────────────

    /**
     * Parse /ip/address or /ipv6/address response.
     *
     * @return array[] [['id', 'address', 'prefix_length', 'network', 'interface', 'disabled', 'dynamic'], ...]
     */
    public function parseIpAddresses(array $raw): array
    {
        return array_map(function (array $entry): array {
            [$ip, $prefix] = $this->splitCidr($entry['address'] ?? '');
            return [
                'id'            => $entry['.id'] ?? null,
                'address'       => $ip,
                'prefix_length' => $prefix,
                'cidr'          => $entry['address'] ?? '',
                'network'       => $entry['network'] ?? null,
                'interface'     => $entry['actual-interface'] ?? $entry['interface'] ?? null,
                'disabled'      => $this->parseBool($entry['disabled'] ?? 'false'),
                'dynamic'       => $this->parseBool($entry['dynamic'] ?? 'false'),
                'invalid'       => $this->parseBool($entry['invalid'] ?? 'false'),
            ];
        }, $raw);
    }

    // ─── Routes ───────────────────────────────────────────────────────────────

    /**
     * Parse /ip/route or /ipv6/route response.
     *
     * @return array[] [['id', 'destination', 'gateway', 'interface', 'distance', 'active', 'dynamic'], ...]
     */
    public function parseRoutes(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'          => $entry['.id'] ?? null,
                'destination' => $entry['dst-address'] ?? $entry['dst'] ?? null,
                'gateway'     => $entry['gateway'] ?? null,
                'interface'   => $entry['routing-mark'] ?? $entry['gateway-interface'] ?? null,
                'distance'    => (int)($entry['distance'] ?? 1),
                'metric'      => (int)($entry['routing-mark'] ?? 0),
                'active'      => $this->parseBool($entry['active'] ?? 'false'),
                'dynamic'     => $this->parseBool($entry['dynamic'] ?? 'false'),
                'disabled'    => $this->parseBool($entry['disabled'] ?? 'false'),
                'protocol'    => $entry['protocol'] ?? 'static',
                'scope'       => (int)($entry['scope'] ?? 30),
            ];
        }, $raw);
    }

    // ─── Neighbor Table (ARP/NDP) ─────────────────────────────────────────────

    /**
     * Parse /ip/arp or /ipv6/neighbor response.
     *
     * @return array[] [['id', 'ip', 'mac', 'interface', 'complete', 'dynamic'], ...]
     */
    public function parseNeighborTable(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'        => $entry['.id'] ?? null,
                'ip'        => $entry['address'] ?? $entry['ip-address'] ?? null,
                'mac'       => strtoupper($entry['mac-address'] ?? ''),
                'interface' => $entry['interface'] ?? null,
                'complete'  => $this->parseBool($entry['complete'] ?? 'false'),
                'dynamic'   => $this->parseBool($entry['dynamic'] ?? 'true'),
                'disabled'  => $this->parseBool($entry['disabled'] ?? 'false'),
                'published' => $this->parseBool($entry['published'] ?? 'false'),
            ];
        }, $raw);
    }

    // ─── Firewall ─────────────────────────────────────────────────────────────

    /**
     * Parse /ip/firewall/filter response.
     *
     * @return array[] [['id', 'chain', 'action', 'src_address', 'dst_address', 'protocol', 'disabled', 'comment'], ...]
     */
    public function parseFirewallRules(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'              => $entry['.id'] ?? null,
                'chain'           => $entry['chain'] ?? 'forward',
                'action'          => $entry['action'] ?? 'accept',
                'src_address'     => $entry['src-address'] ?? null,
                'dst_address'     => $entry['dst-address'] ?? null,
                'src_address_list'=> $entry['src-address-list'] ?? null,
                'dst_address_list'=> $entry['dst-address-list'] ?? null,
                'protocol'        => $entry['protocol'] ?? null,
                'src_port'        => $entry['src-port'] ?? null,
                'dst_port'        => $entry['dst-port'] ?? null,
                'in_interface'    => $entry['in-interface'] ?? null,
                'out_interface'   => $entry['out-interface'] ?? null,
                'disabled'        => $this->parseBool($entry['disabled'] ?? 'false'),
                'dynamic'         => $this->parseBool($entry['dynamic'] ?? 'false'),
                'comment'         => $entry['comment'] ?? null,
            ];
        }, $raw);
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    /**
     * Parse /interface response.
     *
     * @return array[] [['id', 'name', 'type', 'mac', 'mtu', 'running', 'disabled', 'rx_bytes', 'tx_bytes'], ...]
     */
    public function parseInterfaces(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'          => $entry['.id'] ?? null,
                'name'        => $entry['name'] ?? null,
                'type'        => $entry['type'] ?? 'ether',
                'mac'         => strtoupper($entry['mac-address'] ?? ''),
                'mtu'         => (int)($entry['mtu'] ?? 1500),
                'running'     => $this->parseBool($entry['running'] ?? 'false'),
                'disabled'    => $this->parseBool($entry['disabled'] ?? 'false'),
                'slave'       => $this->parseBool($entry['slave'] ?? 'false'),
                'rx_bytes'    => (int)($entry['rx-byte'] ?? 0),
                'tx_bytes'    => (int)($entry['tx-byte'] ?? 0),
                'rx_packets'  => (int)($entry['rx-packet'] ?? 0),
                'tx_packets'  => (int)($entry['tx-packet'] ?? 0),
                'comment'     => $entry['comment'] ?? null,
            ];
        }, $raw);
    }

    // ─── System Info ──────────────────────────────────────────────────────────

    /**
     * Parse /system/resource response.
     */
    public function parseSystemInfo(array $raw): array
    {
        return [
            'uptime'          => $raw['uptime'] ?? null,
            'version'         => $raw['version'] ?? null,
            'build_time'      => $raw['build-time'] ?? null,
            'cpu_count'       => (int)($raw['cpu-count'] ?? 1),
            'cpu_load'        => (int)($raw['cpu-load'] ?? 0),
            'cpu_frequency'   => (int)($raw['cpu-frequency'] ?? 0),
            'memory_total'    => (int)($raw['total-memory'] ?? 0),
            'memory_free'     => (int)($raw['free-memory'] ?? 0),
            'disk_total'      => (int)($raw['total-hdd-space'] ?? 0),
            'disk_free'       => (int)($raw['free-hdd-space'] ?? 0),
            'architecture'    => $raw['architecture-name'] ?? null,
            'board_name'      => $raw['board-name'] ?? null,
            'platform'        => $raw['platform'] ?? 'MikroTik',
        ];
    }

    // ─── BGP ──────────────────────────────────────────────────────────────────

    /**
     * Parse /routing/bgp/peer response.
     */
    public function parseBGPSessions(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'               => $entry['.id'] ?? null,
                'name'             => $entry['name'] ?? null,
                'remote_address'   => $entry['remote-address'] ?? null,
                'remote_as'        => $entry['remote-as'] ?? null,
                'local_address'    => $entry['local-address'] ?? null,
                'state'            => $entry['state'] ?? 'idle',
                'established'      => $this->parseBool($entry['established'] ?? 'false'),
                'uptime'           => $entry['uptime'] ?? null,
                'prefix_count'     => (int)($entry['prefix-count'] ?? 0),
                'updates_sent'     => (int)($entry['updates-sent'] ?? 0),
                'updates_received' => (int)($entry['updates-received'] ?? 0),
                'disabled'         => $this->parseBool($entry['disabled'] ?? 'false'),
            ];
        }, $raw);
    }

    // ─── Neighbor Discovery ───────────────────────────────────────────────────

    /**
     * Parse /ip/neighbor response (MikroTik Neighbor Discovery / CDP / LLDP).
     */
    public function parseNeighborDiscovery(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'            => $entry['.id'] ?? null,
                'interface'     => $entry['interface'] ?? null,
                'address'       => $entry['address'] ?? null,
                'mac'           => strtoupper($entry['mac-address'] ?? ''),
                'identity'      => $entry['identity'] ?? null,
                'platform'      => $entry['platform'] ?? null,
                'version'       => $entry['version'] ?? null,
                'board'         => $entry['board'] ?? null,
                'uptime'        => $entry['uptime'] ?? null,
                'software_id'   => $entry['software-id'] ?? null,
                'interface_name'=> $entry['interface-name'] ?? null,
                'ip_address'    => $entry['ip-address'] ?? null,
            ];
        }, $raw);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Split "192.168.1.1/24" into ["192.168.1.1", 24].
     *
     * @return array{string, int}
     */
    public function splitCidr(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);
        return [$parts[0], isset($parts[1]) ? (int)$parts[1] : 32];
    }

    /**
     * Convert MikroTik boolean strings "true"/"false" to PHP bool.
     */
    public function parseBool(string|bool $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return strtolower($value) === 'true' || $value === '1' || $value === 'yes';
    }
}
