<?php

declare(strict_types=1);

namespace NMS\Vendors\FortiGate;

/**
 * FortiGateParser — normalizes raw FortiGate REST API responses into typed arrays.
 *
 * FortiGate uses object-based responses with nested arrays for interface/address references.
 * This parser flattens references to simple strings/arrays and maps field names to NMS conventions.
 */
class FortiGateParser
{
    // ─── Firewall Policies ────────────────────────────────────────────────────

    /**
     * Parse /cmdb/firewall/policy or /cmdb/firewall/policy6 response.
     *
     * @return array[] [['id', 'name', 'src_interfaces', 'dst_interfaces', 'src_addresses', 'dst_addresses', ...], ...]
     */
    public function parseFirewallPolicies(array $raw, string $ipVersion = 'ipv4'): array
    {
        return array_map(function (array $entry) use ($ipVersion): array {
            return [
                'id'              => $entry['policyid'] ?? null,
                'name'            => $entry['name'] ?? null,
                'ip_version'      => $ipVersion,
                'status'          => $this->mapStatus($entry['status'] ?? 'enable'),
                'action'          => $entry['action'] ?? 'accept',
                'src_interfaces'  => $this->extractNames($entry['srcintf'] ?? []),
                'dst_interfaces'  => $this->extractNames($entry['dstintf'] ?? []),
                'src_addresses'   => $this->extractNames($entry['srcaddr'] ?? []),
                'dst_addresses'   => $this->extractNames($entry['dstaddr'] ?? []),
                'services'        => $this->extractNames($entry['service'] ?? []),
                'schedule'        => $entry['schedule'] ?? 'always',
                'nat_enabled'     => ($entry['nat'] ?? 'disable') === 'enable',
                'log_traffic'     => $entry['logtraffic'] ?? 'all',
                'comments'        => $entry['comments'] ?? null,
                'profile_group'   => $entry['profile-group'] ?? null,
                'av_profile'      => $entry['av-profile'] ?? null,
                'ips_sensor'      => $entry['ips-sensor'] ?? null,
            ];
        }, $raw);
    }

    // ─── Interfaces ───────────────────────────────────────────────────────────

    /**
     * Parse /cmdb/system/interface response.
     */
    public function parseInterfaces(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'          => null,
                'name'        => $entry['name'] ?? null,
                'type'        => $entry['type'] ?? 'physical',
                'ip'          => $entry['ip'] ?? null,
                'mac'         => strtoupper($entry['macaddr'] ?? ''),
                'mtu'         => (int)($entry['mtu'] ?? 1500),
                'status'      => $entry['status'] ?? 'up',
                'role'        => $entry['role'] ?? 'undefined',
                'vdom'        => $entry['vdom'] ?? 'root',
                'description' => $entry['description'] ?? null,
                'alias'       => $entry['alias'] ?? null,
                'speed'       => $entry['speed'] ?? null,
                'running'     => ($entry['link'] ?? 'up') === 'up',
                'disabled'    => ($entry['status'] ?? 'up') === 'down',
            ];
        }, $raw);
    }

    // ─── Routes ───────────────────────────────────────────────────────────────

    /**
     * Parse /cmdb/router/static response.
     */
    public function parseRoutes(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'id'          => $entry['seq-num'] ?? null,
                'destination' => ($entry['dst'] ?? '') . '/' . ($entry['dst-mask'] ?? '0'),
                'gateway'     => $entry['gateway'] ?? null,
                'interface'   => $entry['device'] ?? null,
                'distance'    => (int)($entry['distance'] ?? 10),
                'disabled'    => ($entry['status'] ?? 'enable') === 'disable',
                'comment'     => $entry['comment'] ?? null,
            ];
        }, $raw);
    }

    // ─── Address Objects ──────────────────────────────────────────────────────

    /**
     * Parse /cmdb/firewall/address response.
     */
    public function parseAddressObjects(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'name'     => $entry['name'] ?? null,
                'type'     => $entry['type'] ?? 'ipmask',
                'subnet'   => $entry['subnet'] ?? null,
                'fqdn'     => $entry['fqdn'] ?? null,
                'comment'  => $entry['comment'] ?? null,
                'color'    => (int)($entry['color'] ?? 0),
            ];
        }, $raw);
    }

    // ─── BGP ──────────────────────────────────────────────────────────────────

    /**
     * Parse /monitor/router/bgp/neighbors response.
     */
    public function parseBGPSessions(array $raw): array
    {
        if (isset($raw['neighbors'])) {
            $raw = $raw['neighbors'];
        }
        return array_map(function (array $entry): array {
            return [
                'id'               => null,
                'remote_address'   => $entry['neighbor-ip'] ?? $entry['ip'] ?? null,
                'remote_as'        => $entry['remote-as'] ?? null,
                'state'            => strtolower($entry['state'] ?? 'idle'),
                'established'      => strtolower($entry['state'] ?? '') === 'established',
                'uptime'           => $entry['uptime'] ?? null,
                'prefix_count'     => (int)($entry['accepted-prefix-count'] ?? $entry['prefix-count'] ?? 0),
                'msg_rcvd'         => (int)($entry['msg-rcvd'] ?? 0),
                'msg_sent'         => (int)($entry['msg-sent'] ?? 0),
            ];
        }, $raw);
    }

    // ─── HA Status ────────────────────────────────────────────────────────────

    /**
     * Parse /monitor/system/ha-peer response.
     */
    public function parseHAStatus(array $raw): array
    {
        $members = [];
        $peerList = $raw['results'] ?? $raw;
        if (!is_array($peerList)) {
            $peerList = [];
        }

        foreach ($peerList as $peer) {
            $members[] = [
                'serial'    => $peer['serial_no'] ?? $peer['serial-no'] ?? null,
                'hostname'  => $peer['hostname'] ?? null,
                'state'     => $peer['state'] ?? null,
                'priority'  => (int)($peer['priority'] ?? 0),
                'role'      => ($peer['state'] ?? '') === 'master' ? 'active' : 'standby',
                'sync_state'=> $peer['out-of-sync'] ?? null,
            ];
        }

        return [
            'type'    => 'active-passive',
            'members' => $members,
        ];
    }

    // ─── VIPs ─────────────────────────────────────────────────────────────────

    /**
     * Parse /cmdb/firewall/vip response.
     */
    public function parseVIPs(array $raw): array
    {
        return array_map(function (array $entry): array {
            return [
                'name'        => $entry['name'] ?? null,
                'external_ip' => $entry['extip'] ?? null,
                'external_if' => $entry['extintf'] ?? null,
                'mapped_ip'   => $entry['mappedip'][0]['range'] ?? $entry['extip'] ?? null,
                'type'        => $entry['type'] ?? 'static-nat',
                'comment'     => $entry['comment'] ?? null,
            ];
        }, $raw);
    }

    // ─── System Info ──────────────────────────────────────────────────────────

    /**
     * Parse /monitor/system/status response.
     */
    public function parseSystemInfo(array $raw): array
    {
        $results = $raw['results'] ?? $raw;
        return [
            'hostname'      => $results['hostname'] ?? null,
            'version'       => $results['version'] ?? null,
            'serial'        => $results['serial'] ?? null,
            'model'         => $results['model'] ?? null,
            'platform_type' => $results['platform_type'] ?? null,
            'cpu_usage'     => $results['cpu'] ?? null,
            'memory_usage'  => $results['memory'] ?? null,
            'uptime'        => $results['uptime'] ?? null,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract 'name' values from FortiGate's object reference arrays.
     * e.g. [['name' => 'wan1'], ['name' => 'lan']] → ['wan1', 'lan']
     */
    public function extractNames(array $refs): array
    {
        return array_values(array_filter(array_map(
            fn(array $ref) => $ref['name'] ?? null,
            $refs
        )));
    }

    /**
     * Map FortiGate status strings to normalized form.
     */
    public function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'enable', 'up', 'active' => 'enabled',
            'disable', 'down'        => 'disabled',
            default                  => $status,
        };
    }
}
