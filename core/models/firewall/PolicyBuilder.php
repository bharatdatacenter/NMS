<?php

declare(strict_types=1);

namespace NMS\Core\Models\Firewall;

/**
 * PolicyBuilder
 *
 * Builds normalized firewall policy documents ready for PolicyManager::create().
 *
 * IPv4 inbound:
 *   - Requires a VIP (vip_id) for DNAT
 *   - nat_enabled: true, nat_type: "destination"
 *
 * IPv6 inbound:
 *   - NO VIP — IPv6 addresses are globally routable, no NAT needed
 *   - nat_enabled: false, vip_id: null
 */
class PolicyBuilder
{
    // ─── IPv4 ─────────────────────────────────────────────────────────────────

    /**
     * Build an inbound IPv4 policy with DNAT via VIP.
     *
     * @param array $params {
     *   device_id (required), cluster_id?, name (required),
     *   source_zone?, destination_zone?,
     *   source_addresses[], destination_addresses[], services[],
     *   vip_id (required), mapped_ip (required), mapped_port?,
     *   action?, log_traffic?, schedule?, comments?,
     *   sequence?, ip_assignment_id?, server_id?
     * }
     */
    public function buildIPv4InboundPolicy(array $params): array
    {
        return [
            'ip_version'            => 'ipv4',
            'device_id'             => $params['device_id'],
            'cluster_id'            => $params['cluster_id'] ?? null,
            'name'                  => $params['name'],
            'sequence'              => $params['sequence'] ?? 0,
            'direction'             => 'inbound',
            'source_zone'           => $params['source_zone'] ?? 'wan',
            'destination_zone'      => $params['destination_zone'] ?? 'internal',
            'source_addresses'      => $params['source_addresses'] ?? [],
            'destination_addresses' => $params['destination_addresses'] ?? [],
            'services'              => $params['services'] ?? [],
            'vip_id'                => $params['vip_id'],        // Required for IPv4 inbound DNAT
            'mapped_ip'             => $params['mapped_ip'],     // Private IP behind VIP
            'mapped_port'           => $params['mapped_port'] ?? null,
            'nat_enabled'           => true,                     // Always true for IPv4 DNAT
            'nat_type'              => 'destination',
            'action'                => $params['action'] ?? 'allow',
            'log_traffic'           => $params['log_traffic'] ?? 'security',
            'schedule'              => $params['schedule'] ?? null,
            'comments'              => $params['comments'] ?? null,
            'ip_assignment_id'      => $params['ip_assignment_id'] ?? null,
            'server_id'             => $params['server_id'] ?? null,
        ];
    }

    /**
     * Build an outbound IPv4 policy (SNAT or plain allow).
     */
    public function buildIPv4OutboundPolicy(array $params): array
    {
        return [
            'ip_version'            => 'ipv4',
            'device_id'             => $params['device_id'],
            'cluster_id'            => $params['cluster_id'] ?? null,
            'name'                  => $params['name'],
            'sequence'              => $params['sequence'] ?? 0,
            'direction'             => 'outbound',
            'source_zone'           => $params['source_zone'] ?? 'internal',
            'destination_zone'      => $params['destination_zone'] ?? 'wan',
            'source_addresses'      => $params['source_addresses'] ?? [],
            'destination_addresses' => $params['destination_addresses'] ?? [],
            'services'              => $params['services'] ?? [],
            'vip_id'                => null,
            'mapped_ip'             => $params['mapped_ip'] ?? null,
            'mapped_port'           => null,
            'nat_enabled'           => (bool) ($params['nat_enabled'] ?? false),
            'nat_type'              => $params['nat_type'] ?? null,
            'action'                => $params['action'] ?? 'allow',
            'log_traffic'           => $params['log_traffic'] ?? 'none',
            'schedule'              => $params['schedule'] ?? null,
            'comments'              => $params['comments'] ?? null,
            'ip_assignment_id'      => $params['ip_assignment_id'] ?? null,
            'server_id'             => $params['server_id'] ?? null,
        ];
    }

    // ─── IPv6 ─────────────────────────────────────────────────────────────────

    /**
     * Build an inbound IPv6 policy.
     *
     * IPv6 addresses are globally routable — no VIP or NAT required.
     * nat_enabled is ALWAYS false, vip_id is ALWAYS null.
     */
    public function buildIPv6InboundPolicy(array $params): array
    {
        return [
            'ip_version'            => 'ipv6',
            'device_id'             => $params['device_id'],
            'cluster_id'            => $params['cluster_id'] ?? null,
            'name'                  => $params['name'],
            'sequence'              => $params['sequence'] ?? 0,
            'direction'             => 'inbound',
            'source_zone'           => $params['source_zone'] ?? 'wan',
            'destination_zone'      => $params['destination_zone'] ?? 'internal',
            'source_addresses'      => $params['source_addresses'] ?? [],
            'destination_addresses' => $params['destination_addresses'] ?? [],  // Direct IPv6 address objects, not VIPs
            'services'              => $params['services'] ?? [],
            'vip_id'                => null,    // Never created for IPv6
            'mapped_ip'             => null,    // No NAT mapping for IPv6
            'mapped_port'           => null,
            'nat_enabled'           => false,   // ALWAYS false for IPv6
            'nat_type'              => null,
            'action'                => $params['action'] ?? 'allow',
            'log_traffic'           => $params['log_traffic'] ?? 'security',
            'schedule'              => $params['schedule'] ?? null,
            'comments'              => $params['comments'] ?? null,
            'ip_assignment_id'      => $params['ip_assignment_id'] ?? null,
            'server_id'             => $params['server_id'] ?? null,
        ];
    }

    /**
     * Build an outbound IPv6 policy.
     */
    public function buildIPv6OutboundPolicy(array $params): array
    {
        return [
            'ip_version'            => 'ipv6',
            'device_id'             => $params['device_id'],
            'cluster_id'            => $params['cluster_id'] ?? null,
            'name'                  => $params['name'],
            'sequence'              => $params['sequence'] ?? 0,
            'direction'             => 'outbound',
            'source_zone'           => $params['source_zone'] ?? 'internal',
            'destination_zone'      => $params['destination_zone'] ?? 'wan',
            'source_addresses'      => $params['source_addresses'] ?? [],
            'destination_addresses' => $params['destination_addresses'] ?? [],
            'services'              => $params['services'] ?? [],
            'vip_id'                => null,    // Never for IPv6
            'mapped_ip'             => null,
            'mapped_port'           => null,
            'nat_enabled'           => false,   // Always false for IPv6
            'nat_type'              => null,
            'action'                => $params['action'] ?? 'allow',
            'log_traffic'           => $params['log_traffic'] ?? 'none',
            'schedule'              => $params['schedule'] ?? null,
            'comments'              => $params['comments'] ?? null,
            'ip_assignment_id'      => $params['ip_assignment_id'] ?? null,
            'server_id'             => $params['server_id'] ?? null,
        ];
    }
}
