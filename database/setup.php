<?php

declare(strict_types=1);

/**
 * Creates all MongoDB collections and indexes for NMS.
 * Run via: php database/setup.php
 * Or via Migration::run()
 *
 * The $db variable is injected by Migration::run() or set below when run directly.
 */

if (!isset($db)) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/core/config/app.php';
    $dbInstance = \NMS\Core\Database\MongoDB::getInstance();
    $db = $dbInstance->getDatabase();
}

echo "Creating indexes...\n";

// ─── Devices ───────────────────────────────────────────────────────────────────
$db->devices->createIndex(['ip_address' => 1], ['unique' => true, 'name' => 'ip_address_unique']);
$db->devices->createIndex(['vendor' => 1, 'status' => 1], ['name' => 'vendor_status']);
$db->devices->createIndex(['location.site_id' => 1], ['name' => 'location_site_id']);
$db->devices->createIndex(['location.rack_id' => 1], ['name' => 'location_rack_id']);
$db->devices->createIndex(['cluster_id' => 1], ['name' => 'cluster_id']);
$db->devices->createIndex(['drift.status' => 1], ['name' => 'drift_status']);

// ─── HA Clusters ────────────────────────────────────────────────────────────────
$db->device_clusters->createIndex(['management_ip' => 1], ['unique' => true, 'name' => 'management_ip_unique']);
$db->device_clusters->createIndex(['members.device_id' => 1], ['name' => 'members_device_id']);

// ─── Drift ──────────────────────────────────────────────────────────────────────
$db->config_drift_log->createIndex(['device_id' => 1, 'status' => 1], ['name' => 'device_id_status']);
$db->config_drift_log->createIndex(['detected_at' => -1], ['name' => 'detected_at_desc']);

// ─── IPAM ───────────────────────────────────────────────────────────────────────
$db->ip_assignments->createIndex(['ip_address' => 1], ['unique' => true, 'name' => 'ip_address_unique']);
$db->ip_assignments->createIndex(['pool_id' => 1, 'status' => 1], ['name' => 'pool_id_status']);
$db->ip_assignments->createIndex(['assigned_to.type' => 1, 'assigned_to.id' => 1], ['name' => 'assigned_to']);
$db->ip_assignments->createIndex(['ip_version' => 1], ['name' => 'ip_version']);
$db->ip_pools->createIndex(['network' => 1], ['name' => 'network']);
$db->ip_pools->createIndex(['site_id' => 1, 'ip_version' => 1], ['name' => 'site_id_ip_version']);
$db->ip_blocks->createIndex(['ip_version' => 1], ['name' => 'ip_version']);
$db->ip_assignment_history->createIndex(['ip_address' => 1, 'timestamp' => -1], ['name' => 'ip_history']);
$db->ip_reservations->createIndex(['ip_address' => 1], ['name' => 'ip_address']);
$db->ip_reservations->createIndex(['pool_id' => 1], ['name' => 'pool_id']);

// ─── Firewall ───────────────────────────────────────────────────────────────────
$db->firewall_policies->createIndex(['device_id' => 1, 'sequence' => 1], ['name' => 'device_sequence']);
$db->firewall_policies->createIndex(['cluster_id' => 1, 'sequence' => 1], ['name' => 'cluster_sequence']);
$db->firewall_policies->createIndex(['ip_version' => 1], ['name' => 'ip_version']);
$db->firewall_policy_history->createIndex(['policy_id' => 1, 'changed_at' => -1], ['name' => 'policy_history']);
$db->firewall_vips->createIndex(['ip_version' => 1], ['name' => 'ip_version']);
$db->firewall_address_objects->createIndex(['name' => 1, 'device_id' => 1], ['name' => 'name_device']);
$db->firewall_address_groups->createIndex(['name' => 1, 'device_id' => 1], ['name' => 'name_device']);
$db->firewall_service_objects->createIndex(['name' => 1], ['name' => 'name']);

// ─── Physical Infrastructure ────────────────────────────────────────────────────
$db->sites->createIndex(['code' => 1], ['unique' => true, 'name' => 'code_unique']);
$db->sites->createIndex(['address.coordinates' => '2dsphere'], ['name' => 'coordinates_2dsphere']);
$db->racks->createIndex(['site_id' => 1], ['name' => 'site_id']);
$db->cables->createIndex(['cable_id' => 1], ['unique' => true, 'name' => 'cable_id_unique']);
$db->cables->createIndex(['endpoint_a.device_id' => 1], ['name' => 'endpoint_a_device']);
$db->cables->createIndex(['endpoint_b.device_id' => 1], ['name' => 'endpoint_b_device']);

// ─── Connectivity Paths ──────────────────────────────────────────────────────────
$db->connectivity_paths->createIndex(['source.device_id' => 1, 'source.port_name' => 1], ['name' => 'source_device_port']);
$db->connectivity_paths->createIndex(['destination.device_id' => 1], ['name' => 'destination_device']);
$db->connectivity_paths->createIndex(['cable_ids' => 1], ['name' => 'cable_ids']);
$db->connectivity_paths->createIndex(['valid' => 1], ['name' => 'valid']);

// ─── Audit ───────────────────────────────────────────────────────────────────────
$db->audit_logs->createIndex(['timestamp' => -1], ['name' => 'timestamp_desc']);
$db->audit_logs->createIndex(['user_id' => 1, 'timestamp' => -1], ['name' => 'user_timestamp']);
$db->audit_logs->createIndex(['resource_type' => 1, 'resource_id' => 1], ['name' => 'resource']);
$db->audit_logs->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0, 'name' => 'ttl_expiry']);
$db->audit_logs->createIndex(['idempotency_key' => 1], ['sparse' => true, 'name' => 'idempotency_key']);

// ─── NICs ────────────────────────────────────────────────────────────────────────
$db->server_nics->createIndex(['ims_server_id' => 1], ['name' => 'ims_server_id']);
$db->server_nics->createIndex(['mac_address' => 1], ['unique' => true, 'name' => 'mac_unique']);
$db->server_nics->createIndex(['connected_to.device_id' => 1], ['name' => 'connected_device']);

// ─── VPN ─────────────────────────────────────────────────────────────────────────
$db->vpn_gateways->createIndex(['name' => 1], ['unique' => true, 'name' => 'name_unique']);
$db->vpn_tunnels->createIndex(['gateway_id' => 1], ['name' => 'gateway_id']);
$db->vpn_tunnels->createIndex(['status' => 1], ['name' => 'status']);
$db->vpn_users->createIndex(['username' => 1], ['unique' => true, 'name' => 'username_unique']);

// ─── Topology ────────────────────────────────────────────────────────────────────
$db->topology_views->createIndex(['site_id' => 1], ['name' => 'site_id']);
$db->topology_snapshots->createIndex(['created_at' => -1], ['name' => 'created_at_desc']);

// ─── Device Backups ──────────────────────────────────────────────────────────────
$db->device_backups->createIndex(['device_id' => 1, 'created_at' => -1], ['name' => 'device_backup_time']);

// ─── Notifications ───────────────────────────────────────────────────────────────
$db->notification_rules->createIndex(['enabled' => 1, 'event_type' => 1], ['name' => 'enabled_event_type']);
$db->notification_rules->createIndex(['event_type' => 1, 'min_severity' => 1], ['name' => 'event_severity']);
$db->notification_log->createIndex(['created_at' => -1], ['name' => 'created_at_desc']);
$db->notification_log->createIndex(['channel' => 1, 'status' => 1], ['name' => 'channel_status']);
$db->notification_log->createIndex(['event_type' => 1, 'created_at' => -1], ['name' => 'event_time']);

echo "All indexes created successfully.\n";
