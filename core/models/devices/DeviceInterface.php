<?php

declare(strict_types=1);

namespace NMS\Core\Models\Devices;

/**
 * NetworkDeviceInterface
 *
 * Full abstraction layer for all supported vendors (MikroTik, FortiGate, VyOS, Cisco, Aruba).
 * All vendor adapters implement this interface.
 * Vendor adapters are wrapped with RetryHandler + CircuitBreaker in Phase 3.
 */
interface DeviceInterface
{
    // ─── Connection ───────────────────────────────────────────────────────────

    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;

    // ─── IP Management ────────────────────────────────────────────────────────

    /** @param string $family 'ipv4' | 'ipv6' | 'all' */
    public function getIpAddresses(string $family = 'ipv4'): array;
    public function addIpAddress(string $ip, string $interface): bool;
    public function removeIpAddress(string $ip): bool;

    // ─── Firewall ─────────────────────────────────────────────────────────────

    public function getFirewallRules(): array;
    public function addFirewallRule(array $rule): bool;
    public function removeFirewallRule(string $ruleId): bool;

    // ─── Interfaces ───────────────────────────────────────────────────────────

    public function getInterfaces(): array;
    public function getInterfaceStatus(string $interface): array;

    // ─── System ───────────────────────────────────────────────────────────────

    public function getSystemInfo(): array;
    public function getNeighborDiscovery(): array;  // CDP/LLDP/MikroTik Neighbor
    public function backupConfig(): string;
    public function restoreConfig(string $config): bool;

    // ─── Drift Detection ──────────────────────────────────────────────────────

    /**
     * Returns structured, normalized config sections for drift comparison.
     * NOT raw config text — avoids issues with dynamic state (uptime counters,
     * encrypted password salts, byte counters, last-login timestamps).
     *
     * @return array ['firewall' => [...], 'interfaces' => [...]]
     */
    public function getConfigSections(): array;

    // ─── HA Cluster ───────────────────────────────────────────────────────────

    /** @return array Cluster state, peer status, failover count */
    public function getHAStatus(): array;

    // ─── Safe Command Execution ───────────────────────────────────────────────

    /** Execute an allowlisted read-only command. */
    public function executeCommand(string $command): string;

    /** Returns vendor-specific allowlist of safe commands. */
    public function getAllowedCommands(): array;
}
