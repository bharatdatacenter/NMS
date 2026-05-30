# NMS (Network Management System) - Comprehensive Detailed Plan

## Table of Contents
1. [Overview](#overview)
2. [Technology Stack](#technology-stack)
3. [System Architecture](#system-architecture)
4. [Multi-Vendor Device Management](#1-multi-vendor-device-management)
5. [IP Address Management (IPAM)](#2-ip-address-management-ipam)
6. [Firewall Policy Management](#3-firewall-policy-management)
7. [Routing Management](#4-routing-management)
8. [Neighbor Table Management (ARP + NDP)](#5-neighbor-table-management-arp--ndp)
9. [Infrastructure Topology & Physical Mapping](#6-infrastructure-topology--physical-mapping)
10. [Zabbix Monitoring Integration](#7-zabbix-monitoring-integration)
11. [Compliance & Audit Logs](#8-compliance--audit-logs)
12. [VPN Management](#9-vpn-management)
13. [IMS Integration](#10-ims-integration)
14. [Authentication & Authorization](#11-authentication--authorization)
15. [Complete Database Schema (MongoDB)](#12-complete-database-schema-mongodb)
16. [Complete API Reference](#13-complete-api-reference)
17. [Frontend Specification](#14-frontend-specification)
18. [Project Structure](#15-project-structure)
19. [Implementation Phases](#16-implementation-phases)

---

## Overview

The NMS (Network Management System) is an infrastructure architecture and configuration platform designed to provide unified control over multi-vendor, multi-site network infrastructure. The system's primary objective is **knowing and controlling the full physical and logical layout of the infrastructure** — from which cable is plugged into which port, to which IPs are routed through which device.

**Core Objectives:**
- **Infrastructure Architecture**: Complete physical + logical mapping of all sites, racks, devices, ports, and connections across a 3D spatial model (building/floor/room/row/rack/U-position)
- **Configuration Management**: Centralized IPAM, routing, ARP/NDP, and firewall policy management with drift detection for out-of-band changes
- **Multi-Site Awareness**: 3D infrastructure model spanning multiple data centers, racks, and interconnections — network infrastructure cannot be modeled on a flat 2D plane when devices span multiple physical sites
- **IMS Synergy**: Combined with IMS, provides a complete hardware-to-network picture — what's plugged where, physically and logically. IMS owns hardware/OS; NMS owns network identity (IPs, routes, firewall rules, cable paths)
- **HA-Aware**: Models device clusters (FortiGate HA pairs, VRRP groups, switch stacks) as first-class entities, not just individual devices
- **Dual-Stack (IPv4 + IPv6)**: Every IP-related subsystem handles both address families natively

**Monitoring Scope**: NMS does **not** duplicate monitoring. All device health monitoring (CPU, memory, traffic, alerting) is delegated to Zabbix via its API. NMS focuses exclusively on infrastructure architecture and configuration.

**Why MongoDB/NoSQL:**
Network infrastructure is inherently hierarchical and graph-like. A data center contains racks, racks contain devices, devices have ports, ports connect to other ports across different racks and sites. This multi-dimensional relationship cannot be effectively modeled in flat relational tables. MongoDB's document model allows:
- Nested site → rack → device → port hierarchies naturally representing the 3D spatial layout of infrastructure
- Flexible schemas per vendor (each device type has different attributes)
- Embedded connection graphs without expensive JOINs
- Atomic `findOneAndUpdate` operations for race-free IP allocation
- Natural representation of device configurations (already JSON from vendor APIs)
- `$graphLookup` for bounded topology traversal (cable paths through patch panels, max depth 6)
- Geospatial indexes for site coordinate queries across a global deployment

---

## Technology Stack

| Component | Technology | Notes |
|-----------|------------|-------|
| **Backend** | PHP 8.3+ | Matches IMS for code sharing |
| **Database** | MongoDB 7.0+ | Document-oriented, graph-capable |
| **Cache / Broker** | Redis 7.0+ (required) | JWT blocklist, rate limiting, Zabbix data cache (60s TTL), device status cache, idempotency key store |
| **Secrets** | HashiCorp Vault (or provider abstraction) | Device credentials, HMAC signing keys, VPN PSKs. Fallback: app-layer encryption with OS keyring key (temporary) |
| **Authentication** | JWT (HS256 → future RS256) | Shared with IMS via API. Key stored in Vault, not .env |
| **Authorization** | RBAC (shared with IMS) | Permissions validated via JWT claims. IMS owns users/roles/permissions — no RBAC DB in NMS |
| **API Architecture** | RESTful JSON API | Standardized responses |
| **Design Pattern** | MVC with Service Layer | Clean separation |
| **Monitoring** | Zabbix API | External monitoring integration (read-only from NMS) |
| **Resilience** | Exponential Backoff + Circuit Breaker | All vendor API calls: base 2s, max 30s, jitter. Circuit breaker per device after 5 consecutive failures |
| **Frontend** | PHP Templates + Tailwind CSS + Alpine.js | Server-rendered views, no separate SPA build. Alpine.js for lightweight reactivity |
| **Visualization** | D3.js / Cytoscape.js / Three.js | Network topology (Cytoscape.js), 3D rack views (Three.js), charts (Chart.js) |
| **PHP MongoDB Driver** | mongodb/mongodb (Composer) | Official MongoDB PHP library |
| **Ticketing** | Shared IMS Ticket System | NMS creates/updates tickets in IMS via M2M API — no separate ticketing in NMS |

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────��───────────────┐
│                                FRONTEND                                      │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐    │
│  │ Dashboard │ │ Topology  │ │   IPAM    │ │  Devices  │ │   Drift   │    │
│  │           │ │ (3D Sites)│ │           │ │ +Clusters │ │  Manager  │    │
│  └───────────┘ └───────────┘ └───────────┘ └───────────┘ └───────────┘    │
└────────────────────────────────────���────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            NMS API LAYER                                     │
│  ┌───────────────��─────────────────────────────────────────────────────┐    │
│  │                      api/api.php (Router)                            │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│  ┌──────────┬──────────┬──────────┬──────────┬──────────┬──────────┐       │
│  │   Auth   │ Devices  │   IPAM   │ Firewall │  Routes  │ Topology │       │
│  │ + RBAC   │+Clusters │          │          │ +BGP Mon │          │       │
│  └──────────┴──────────┴──────────┴──────────┴──────────┴──────────┘       │
│  ┌──────────┬──────────┬──────────┐                                        │
│  │  Drift   │   IMS    │  Audit   │                                        │
│  │ Resolver │ Integr.  │          │                                        │
│  └──────────┴──────────┴──────────┘                                        │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          CORE SERVICE LAYER                                  │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────��                │
│  │ Device Manager │  │  IPAM Service  │  │Firewall Service│                │
│  └────────────────┘  └────────────────┘  └────────────────┘                │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐                │
│  │ Route Manager  │  │Neighbor Manager│  │Topology Builder│                │
│  │  + BGP Monitor │  │  (ARP + NDP)   │  │ + Path Cache   │                │
│  └────────────────┘  └────────────────┘  └────────────────┘                │
│  ┌────────────────┐  ┌────────────────┐                                    │
│  │ Zabbix Client  │  │ Drift Detector │                                    │
│  │  (Monitoring)  │  │                │                                    │
│  └────────────────┘  └────────────────┘                                    │
│  ┌────────────────┐  ┌────────────────┐                                    │
│  │Cluster Manager │  │Secrets Manager │ ← Vault / Provider Abstraction     │
│  └────────────────┘  └────────────────┘                                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
              ┌─────────────────────┼──────────────────────┐
              ▼                     ▼                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                      VENDOR ADAPTER LAYER                                    │
│  ┌──────────┐ ┌─────────���┐ ┌──────────┐ ┌──────────┐ ┌──────────┐         │
│  │ MikroTik │ │ FortiGate│ │   VyOS   │ │  Cisco   │ │  Aruba   │         │
│  │ Adapter  │ │ Adapter  │ │ Adapter  │ │ Adapter  │ │ Adapter  │         │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘         │
│  All adapters use: RetryHandler (exponential backoff) + CircuitBreaker      │
└─────────────────────────────────────────────────────────────────────────────┘
              │                     │                      │
              ▼                     ▼                      ▼
       ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
       │   MikroTik   │ │  FortiGate   │ │    VyOS      │
       │   Routers    │ │  Firewalls   │ │   Routers    │
       └──────────────┘ └──────────────┘ └──────────────┘

              │                                    │
     ┌────────┴─────────┐               ┌─────────┴──────────┐
     ▼                  ▼               ▼                    ▼
┌──────────┐    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Redis   │    │   MongoDB    │  │ HashiCorp    │  │   Zabbix     │
│ (Cache)  │    │  (Primary)   │  │   Vault      │  │  (Monitor)   │
└──────────┘    └──────────────┘  └──────────────┘  └──────────────┘
```

---

## 1. Multi-Vendor Device Management

### 1.1 Supported Vendors & API Details

#### MikroTik RouterOS REST API

**API Base URL:** `https://{device_ip}/rest`

**Authentication:** HTTP Basic Auth or API Token

**Key Endpoints:**
| Function | Method | Endpoint | Description |
|----------|--------|----------|-------------|
| Get IP Addresses | GET | `/ip/address` | List all IP addresses |
| Add IP Address | PUT | `/ip/address` | Add new IP address |
| Delete IP Address | DELETE | `/ip/address/{id}` | Remove IP address |
| Get Routes | GET | `/ip/route` | List all routes |
| Add Static Route | PUT | `/ip/route` | Add static route |
| Delete Route | DELETE | `/ip/route/{id}` | Remove route |
| Get ARP Table | GET | `/ip/arp` | List ARP entries |
| Add Static ARP | PUT | `/ip/arp` | Add static ARP |
| Get Interfaces | GET | `/interface` | List interfaces |
| Get Firewall Filter | GET | `/ip/firewall/filter` | Firewall rules |
| Add Firewall Rule | PUT | `/ip/firewall/filter` | Add firewall rule |
| Get NAT Rules | GET | `/ip/firewall/nat` | NAT configuration |
| Get System Info | GET | `/system/resource` | CPU, memory, uptime |
| Get Identity | GET | `/system/identity` | Device name |
| Get Neighbors | GET | `/ip/neighbor` | Discovery protocol |
| Get BGP Peers | GET | `/routing/bgp/peer` | BGP session states |
| Get IPv6 Addresses | GET | `/ipv6/address` | IPv6 address list |
| Get IPv6 Routes | GET | `/ipv6/route` | IPv6 routing table |
| Get ND Table | GET | `/ipv6/neighbor` | IPv6 neighbor discovery |

**Request Example:**
```php
// MikroTik REST API Call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://192.168.1.1/rest/ip/address");
curl_setopt($ch, CURLOPT_USERPWD, "admin:password");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
```

**Response Example:**
```json
[
    {
        ".id": "*1",
        "address": "192.168.1.1/24",
        "network": "192.168.1.0",
        "interface": "ether1",
        "actual-interface": "ether1",
        "invalid": "false",
        "dynamic": "false",
        "disabled": "false"
    }
]
```

---

#### Fortinet FortiGate REST API

**API Base URL:** `https://{device_ip}/api/v2`

**Authentication:** API Token in header `Authorization: Bearer {token}`

**Key Endpoints:**
| Function | Method | Endpoint | Description |
|----------|--------|----------|-------------|
| Get Firewall Policies | GET | `/cmdb/firewall/policy` | All firewall policies |
| Create Policy | POST | `/cmdb/firewall/policy` | Create new policy |
| Update Policy | PUT | `/cmdb/firewall/policy/{id}` | Update policy |
| Delete Policy | DELETE | `/cmdb/firewall/policy/{id}` | Delete policy |
| Get Addresses | GET | `/cmdb/firewall/address` | Address objects |
| Create Address | POST | `/cmdb/firewall/address` | Create address object |
| Get Address Groups | GET | `/cmdb/firewall/addrgrp` | Address groups |
| Get Services | GET | `/cmdb/firewall.service/custom` | Service definitions |
| Get Interfaces | GET | `/cmdb/system/interface` | Network interfaces |
| Get Static Routes | GET | `/cmdb/router/static` | Static routes |
| Create Static Route | POST | `/cmdb/router/static` | Add static route |
| Get VIP (DNAT) | GET | `/cmdb/firewall/vip` | Virtual IPs |
| Get System Status | GET | `/monitor/system/status` | System health |
| Get HA Status | GET | `/monitor/system/ha-peer` | HA cluster peer status |
| Get BGP Neighbors | GET | `/monitor/router/bgp/neighbors` | BGP session states |
| Get IPv6 Policies | GET | `/cmdb/firewall/policy6` | IPv6 firewall policies |

**Policy Object Structure:**
```json
{
    "policyid": 1,
    "name": "Allow-Web-Server",
    "srcintf": [{"name": "wan1"}],
    "dstintf": [{"name": "internal"}],
    "srcaddr": [{"name": "all"}],
    "dstaddr": [{"name": "WebServer-IP"}],
    "service": [{"name": "HTTP"}, {"name": "HTTPS"}],
    "action": "accept",
    "status": "enable",
    "logtraffic": "all",
    "comments": "Allow HTTP/HTTPS to web server"
}
```

---

#### VyOS REST API

**API Base URL:** `https://{device_ip}/`

**Authentication:** API Key in POST data

**Key Endpoints:**
| Function | Method | Endpoint | Description |
|----------|--------|----------|-------------|
| Configure | POST | `/configure` | Set configuration |
| Retrieve | POST | `/retrieve` | Get configuration |
| Show | POST | `/show` | Operational commands |
| Generate | POST | `/generate` | Generate configs |
| Reset | POST | `/reset` | Reset functions |

**Common Configuration Paths:**
```
# IP Address
interfaces/ethernet/{interface}/address

# Static Route
protocols/static/route/{network}/next-hop/{gateway}

# Firewall Zone
zone-policy/zone/{zone-name}/interface

# Firewall Rules
firewall/name/{rule-set}/rule/{number}/action

# BGP
protocols/bgp/{asn}/neighbor/{ip}
```

---

#### Cisco IOS-XE RESTCONF API

**API Base URL:** `https://{device_ip}/restconf/data`

**Authentication:** HTTP Basic Auth

**Key Endpoints:**
| Function | Method | Endpoint | Description |
|----------|--------|----------|-------------|
| Get Interfaces | GET | `/ietf-interfaces:interfaces` | All interfaces |
| Get Routes | GET | `/ietf-routing:routing` | Routing table |
| Get ACLs | GET | `/Cisco-IOS-XE-acl:acl` | Access control lists |
| Get Native Config | GET | `/Cisco-IOS-XE-native:native` | Full config |

**Headers Required:**
```
Accept: application/yang-data+json
Content-Type: application/yang-data+json
```

---

#### Aruba CX REST API

**API Base URL:** `https://{device_ip}/rest/v10.04`

**Authentication:** Session-based (login first)

**Key Endpoints:**
| Function | Method | Endpoint | Description |
|----------|--------|----------|-------------|
| Login | POST | `/login` | Get session cookie |
| Get VLANs | GET | `/system/vlans` | VLAN configuration |
| Get Interfaces | GET | `/system/interfaces` | Interface list |
| Get Routes | GET | `/system/routes` | Routing table |
| Get ACLs | GET | `/system/acls` | Access lists |
| Logout | POST | `/logout` | End session |

---

### 1.2 Device Abstraction Layer

**Interface Definition:**
```php
interface NetworkDeviceInterface {
    // Connection
    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;

    // IP Management
    public function getIpAddresses(string $family = 'ipv4'): array;  // 'ipv4' | 'ipv6' | 'all'
    public function addIpAddress(string $ip, string $interface): bool;
    public function removeIpAddress(string $ip): bool;

    // Routing
    public function getRoutes(string $family = 'ipv4'): array;
    public function addStaticRoute(string $destination, string $gateway, ?string $interface = null): bool;
    public function removeRoute(string $destination): bool;

    // Neighbor Table (ARP for IPv4, NDP for IPv6)
    public function getNeighborTable(string $protocol = 'arp'): array;  // 'arp' | 'ndp'
    public function addStaticNeighbor(string $ip, string $mac, string $interface): bool;
    public function removeNeighbor(string $ip): bool;

    // Firewall
    public function getFirewallRules(): array;
    public function addFirewallRule(array $rule): bool;
    public function removeFirewallRule(string $ruleId): bool;

    // Interfaces
    public function getInterfaces(): array;
    public function getInterfaceStatus(string $interface): array;

    // System
    public function getSystemInfo(): array;
    public function getNeighborDiscovery(): array;  // CDP/LLDP neighbors
    public function backupConfig(): string;
    public function restoreConfig(string $config): bool;

    // Drift Detection — returns structured, normalized config sections
    // NOT raw config text (avoids normalization problems with dynamic state like
    // uptime counters, encrypted password salts, byte counters, last-login timestamps)
    public function getConfigSections(): array;  // Returns ['routes' => [...], 'firewall' => [...], 'arp' => [...]]

    // Dynamic Routing (read-only monitoring)
    public function getBGPSessions(): array;
    public function getBGPPrefixesForRange(string $cidr): array;  // Targeted query: only prefixes overlapping this CIDR
    public function getOSPFNeighbors(): array;

    // HA Cluster
    public function getHAStatus(): array;  // Cluster state, peer status, failover count

    // Safe Command Execution (allowlisted read-only commands only)
    public function executeCommand(string $command): string;
    public function getAllowedCommands(): array;  // Returns vendor-specific allowlist
}
```

**Vendor Command Allowlists (for safe remote execution):**
```
MikroTik:  ping, traceroute, /tool/torch, /system/resource/print, /interface/print
FortiGate: get system status, get router info routing-table all, diagnose sys top,
           execute ping, execute traceroute, get system performance status
VyOS:      show interfaces, show ip route, show ip bgp summary, ping, traceroute
Cisco:     show ip route, show interfaces, show ip bgp summary, ping, traceroute
Aruba:     show interfaces, show ip route, show vlan, ping, traceroute
```

**Retry & Circuit Breaker Policy (all vendor adapters):**
```
Retry:
  - max_retries: 3
  - backoff: exponential with jitter (2s, ~4s, ~8s)
  - max_backoff: 30s
  - retryable: connection timeout, 5xx responses, rate limit (429)
  - NOT retryable: 4xx auth errors, 404

Circuit Breaker (per device):
  - failure_threshold: 5 consecutive failures
  - cooldown_period: 60s
  - state: closed → open (after 5 failures) → half_open (after cooldown) → closed (on success)
  - When open: all calls fail fast with "device unreachable" without hitting the device
  - On state change: update device.status and log to audit
```

---

### 1.3 Device Collections (MongoDB)

```javascript
// ─── devices collection ───
{
    _id: ObjectId,
    name: "Edge-FW-01",
    hostname: "edge-fw-01.dc1.example.com",
    ip_address: "192.168.1.1",                  // Management IP
    vendor: "fortigate",                        // mikrotik | fortigate | vyos | cisco | aruba
    model: "FortiGate 100F",
    serial_number: "FGT100F1234567",
    firmware_version: "7.4.3",

    // Physical location (embedded for fast queries)
    location: {
        site_id: ObjectId,
        site_name: "DC1-Amsterdam",
        building: "A",
        floor: "2",
        room: "Server Room 1",
        rack_id: ObjectId,
        rack_name: "R-A2-07",
        rack_unit: 42,
        rack_side: "front"
    },

    role: "edge_firewall",                      // edge_firewall | core_router | distribution_switch |
                                                // access_switch | vpn_gateway | load_balancer | patch_panel
    status: "online",                           // online | offline | maintenance | unreachable | unknown
    last_seen: ISODate,
    last_backup: ISODate,

    // HA Cluster membership (null if standalone)
    cluster_id: ObjectId,                       // Reference to device_clusters collection
    cluster_role: "primary",                    // primary | secondary | member | null

    // Drift detection state
    drift: {
        status: "clean",                        // clean | drifted | unknown
        last_checked: ISODate,
        last_drifted: ISODate,
        open_drift_count: 0
    },

    // Circuit breaker state
    circuit_breaker: {
        state: "closed",                        // closed | open | half_open
        consecutive_failures: 0,
        last_failure: ISODate,
        cooldown_until: ISODate
    },

    // Port inventory (physical ports on this device)
    ports: [
        {
            name: "port1",
            type: "ethernet",                   // ethernet | sfp | sfp+ | qsfp | console | usb
            speed_mbps: 1000,
            mac_address: "AA:BB:CC:DD:EE:01",
            admin_status: "up",
            oper_status: "up",
            description: "WAN uplink to ISP-1",

            // What is physically plugged into this port
            connection: {
                connected_to_device_id: ObjectId,
                connected_to_device_name: "ISP-Router-1",
                connected_to_port: "ge-0/0/1",
                cable_type: "cat6a",
                cable_id: "C-A2-07-001",
                patch_panel_device_id: ObjectId,    // Reference to patch panel device (if routed through one)
                patch_panel_port: "F-12",           // Front port label on patch panel
                connected_at: ISODate,
                verified: true,
                verified_by: ObjectId,
                verified_at: ISODate
            },

            // Logical configuration on this port
            ip_addresses: ["85.209.161.1/24"],
            ip6_addresses: ["2001:db8::1/64"],      // IPv6 addresses
            vlan_id: null,
            mtu: 1500
        }
    ],

    // Zabbix mapping
    zabbix: {
        host_id: "10084",
        host_name: "Edge-FW-01",
        last_synced: ISODate,
        auto_import: true
    },

    notes: "Primary edge firewall for DC1",
    tags: ["production", "dc1", "edge"],

    created_at: ISODate,
    updated_at: ISODate,
    created_by: ObjectId
}

// ─── device_credentials collection ───
// Stores ONLY vault references, never actual secrets
{
    _id: ObjectId,
    device_id: ObjectId,
    auth_type: "api_key",                       // basic | api_key | ssh_key | certificate

    // Vault references (actual credentials stored in Vault, NEVER in MongoDB)
    vault: {
        provider: "hashicorp_vault",            // hashicorp_vault | aws_secrets | azure_keyvault | app_encrypted (fallback)
        path: "nms/devices/edge-fw-01/creds",  // Vault path or secret ARN
        version: 3,                             // Current version (tracks rotation)
        last_rotated: ISODate
    },

    // Non-sensitive connection config (safe to store in MongoDB)
    port: 443,
    verify_ssl: true,
    timeout: 30,

    created_at: ISODate,
    updated_at: ISODate
}

// ─── device_clusters collection ───
// Models HA pairs, VRRP groups, switch stacks as first-class entities
{
    _id: ObjectId,
    name: "DC1-FW-Cluster-01",
    cluster_type: "active_passive",             // active_passive | active_active | vrrp | stack

    // The address to push configs to (cluster VIP or primary management IP)
    management_ip: "192.168.1.200",
    vendor: "fortigate",

    // Member devices
    members: [
        {
            device_id: ObjectId,
            device_name: "Edge-FW-01",
            role: "primary",                    // primary | secondary | member
            priority: 200,
            node_ip: "192.168.1.1",             // Individual node IP for status checks
            status: "active"                    // active | standby | faulty
        },
        {
            device_id: ObjectId,
            device_name: "Edge-FW-02",
            role: "secondary",
            priority: 100,
            node_ip: "192.168.1.2",
            status: "standby"
        }
    ],

    // Cluster-level health
    status: "healthy",                          // healthy | degraded | split_brain | failed
    last_failover: ISODate,
    failover_count: 3,

    // VRRP-specific (for router clusters)
    vrrp: {
        group_id: 10,
        virtual_ip: "10.0.0.1"
    },

    created_at: ISODate,
    updated_at: ISODate
}

// ─── cluster_events collection ───
{
    _id: ObjectId,
    cluster_id: ObjectId,
    event_type: "failover",                     // failover | member_join | member_leave | split_brain
    previous_primary: ObjectId,
    new_primary: ObjectId,
    detected_at: ISODate,
    auto_detected: true
}

// ─── config_drift_log collection ───
// Tracks out-of-band config changes detected by DriftDetector
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // null if standalone device
    detected_at: ISODate,

    // What sections changed (compared via structured API, NOT raw config hash)
    affected_sections: ["firewall.policies", "ip.routes"],

    // Structured diff of what changed
    diff: [
        {
            section: "firewall.policy",
            action: "added",                    // added | removed | modified
            identifier: "Policy ID 99",
            device_value: { /* from device API */ },
            nms_value: null                     // null = not in NMS
        }
    ],

    // Resolution
    status: "open",                             // open | pushed | pulled | ignored
    resolved_by: ObjectId,
    resolved_at: ISODate,
    resolution_note: "Emergency rule by NOC during DDoS"
}

// ─── device_backups collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    backup_type: "full",                        // full | partial | running | startup
    config_data: "... full config ...",
    config_hash: "sha256:...",
    file_size: 45678,
    notes: "Pre-upgrade backup",
    created_at: ISODate,
    created_by: ObjectId
}
```

### 1.4 Drift Detection Strategy

The DriftDetector service compares NMS state against actual device state **section by section using structured API responses**, not by hashing raw config text.

**Why not hash the full running config?** Vendor running configs contain dynamic state that changes constantly: uptime counters, encrypted password salts that rotate, byte counters, last-login timestamps. Hashing the full config would produce false positives on every poll.

**Instead:** Each vendor adapter's `getConfigSections()` returns normalized typed arrays:
- `routes`: Array of `{destination, gateway, interface, distance}`
- `firewall`: Array of `{id, name, action, src, dst, services}`
- `arp`: Array of `{ip, mac, interface, type}`
- `interfaces`: Array of `{name, ip, status, mtu}`

These are compared field-by-field against what NMS expects. Only meaningful differences produce drift entries.

**Poll Intervals (configurable per device role):**

| Device Role       | Poll Interval | Rationale                            |
|-------------------|--------------|--------------------------------------|
| Edge Firewall     | 5 min        | High-risk, high-change zone          |
| Core Router       | 10 min       | Critical but lower change frequency |
| Access Switch     | 30 min       | Low change frequency                |
| Offline/Unreachable | Disabled  | Skip unreachable devices             |

**Drift Resolution Workflow:**
```
1. DriftDetector finds diff between NMS and device
2. Writes config_drift_log entry
3. Updates device.drift.status = "drifted"
4. (Optional) Triggers webhook/Zabbix alert
5. Operator reviews drift in UI
6. Operator chooses:
   - PUSH: NMS overwrites device config (enforce compliance)
   - PULL: Accept device state into NMS (acknowledge emergency change)
   - IGNORE: Dismiss as known acceptable difference
```

---

## 2. IP Address Management (IPAM)

### 2.1 IPAM Concepts

**Hierarchy:**
```
IP Blocks (from ARIN/RIPE/etc.)
    └── IP Pools (allocated to data centers)
        └── Subnets (assigned to purposes)
            └── IP Addresses (assigned to devices/servers)
```

**IP Types:**
- **L2 (Layer 2)**: IPs directly on interface, within same broadcast domain. Gateway in same subnet. No static routes needed on router.
- **L3 (Layer 3)**: Routed IPs, require static route. Typically /32 (IPv4) or /128 (IPv6) for dedicated servers. Requires static route on router pointing to server's L2 IP.

**IPv4 vs IPv6 Differences in IPAM:**

| Concept | IPv4 | IPv6 |
|---------|------|------|
| Block size | /22 (1024 addr) | /32 or /48 (billions) |
| Subnet for servers | /24, /28 | /64 (standard) |
| Host route | /32 | /128 |
| Usable count | Total - 2 (net + bcast) | All (no broadcast in IPv6) |
| NAT | Common (L3 VIP/DNAT) | Not used |
| Gateway | Same-subnet IP | Link-local (fe80::) |
| Utilization tracking | Per-IP | Per /64 allocation |

### 2.2 IPAM Collections (MongoDB)

```javascript
// ─── ip_blocks collection ───
// Top-level IP allocations from registries
{
    _id: ObjectId,
    network: "85.209.160.0/22",
    ip_version: "ipv4",                         // "ipv4" | "ipv6" — REQUIRED on all IP collections
    prefix_length: 22,
    total_addresses: 1024,
    source: "RIPE",
    rir_handle: "PA-85-209-160",
    whois_info: { /* cached WHOIS data */ },
    description: "Primary allocation from RIPE",
    status: "active",                           // active | reserved | deprecated
    created_at: ISODate
}

// ─── ip_pools collection ───
{
    _id: ObjectId,
    block_id: ObjectId,
    name: "DC1-Public-Pool-1",
    network: "85.209.161.0/24",
    ip_version: "ipv4",                         // REQUIRED
    prefix_length: 24,
    gateway_ip: "85.209.161.1",
    first_usable_ip: "85.209.161.2",
    last_usable_ip: "85.209.161.254",
    total_addresses: 254,
    used_addresses: 87,
    reserved_addresses: 5,
    utilization_percent: 36.22,

    pool_type: "public",                        // public | private | management | vpn | infrastructure
    allocation_type: "layer3",                  // layer2 | layer3 | mixed

    vlan_id: null,

    // Which site/device manages this pool
    site_id: ObjectId,
    datacenter: "DC1-Amsterdam",
    router_device_id: ObjectId,                 // Router managing this pool (or cluster_id)
    router_cluster_id: ObjectId,                // HA cluster if applicable (takes precedence)
    interface_name: "port1",

    description: "Public IPs for DC1 web servers",
    auto_assign: true,
    status: "active",                           // active | full | reserved | deprecated
    created_at: ISODate,
    updated_at: ISODate
}

// ─── IPv6 pool example ───
// {
//     ip_version: "ipv6",
//     network: "2001:db8::/32",
//     prefix_length: 32,
//     // For IPv6, track at /64 allocation level (not per-IP)
//     allocated_prefixes: 147,                  // Number of /64s allocated
//     total_prefixes: 4294967296,               // Total /64s in a /32
//     gateway_ip: "fe80::1",                    // Link-local gateway (IPv6 convention)
// }

// ─── ip_subnets collection ───
{
    _id: ObjectId,
    pool_id: ObjectId,
    parent_subnet_id: null,
    network: "85.209.161.0/28",
    ip_version: "ipv4",                         // REQUIRED
    prefix_length: 28,
    gateway_ip: "85.209.161.1",
    first_usable_ip: "85.209.161.2",
    last_usable_ip: "85.209.161.14",
    total_addresses: 14,
    used_addresses: 6,
    purpose: "Web Servers",
    vlan_id: null,
    description: "Subnet for public-facing web servers",
    status: "active",
    created_at: ISODate
}

// ─── ip_assignments collection ───
{
    _id: ObjectId,
    ip_address: "85.209.161.100",
    ip_version: "ipv4",                         // REQUIRED
    pool_id: ObjectId,
    subnet_id: ObjectId,

    assignment_type: "layer3",                  // layer2 | layer3 | virtual | floating
    mac_address: "AA:BB:CC:11:22:33",

    assigned_to: {
        type: "server",                         // server | vm | container | network_device | service | reserved
        id: "ims-server-uuid-123",
        name: "web-server-01",
        site_id: ObjectId
    },

    // For L3 assignments (IPv4: /32 route, IPv6: /128 route)
    routing: {
        gateway_ip: "10.0.1.50",               // Next-hop (server's L2 IP)
        static_route_added: true,
        route_id: ObjectId,
        neighbor_entry_added: true,             // ARP (IPv4) or NDP (IPv6)
        neighbor_id: ObjectId
    },

    firewall_policy_ids: [ObjectId, ObjectId],

    hostname: "web-server-01.example.com",
    reverse_dns: "100.161.209.85.in-addr.arpa",

    status: "active",                           // active | reserved | quarantine | released
    lease_expires: null,

    description: "Primary public IP for web-server-01",
    tags: ["production", "web"],
    notes: null,

    created_at: ISODate,
    updated_at: ISODate,
    created_by: ObjectId,
    last_modified_by: ObjectId
}

// ─── ip_assignment_history collection ───
{
    _id: ObjectId,
    ip_address: "85.209.161.100",
    action: "assigned",                         // assigned | released | modified | reserved | quarantined
    previous_state: { /* full document snapshot */ },
    new_state: { /* full document snapshot */ },
    assigned_to: {
        type: "server",
        id: "ims-server-uuid-123",
        name: "web-server-01"
    },
    reason: "Manual assignment via NMS",
    performed_by: ObjectId,
    performed_at: ISODate
}

// ─── ip_reservations collection ───
{
    _id: ObjectId,
    ip_address: "85.209.161.1",
    ip_version: "ipv4",                         // REQUIRED
    pool_id: ObjectId,
    purpose: "Gateway",                         // Gateway | HSRP VIP | Future Use
    reserved_until: null,
    reserved_by: ObjectId,
    created_at: ISODate
}
```

### 2.3 IPAM Workflows

**Atomic IP Allocation (Race-Condition Safe)**

The original plan had a race condition: "find next IP → create assignment → update counters" as separate steps. Two concurrent requests could claim the same IP.

**Fix**: Use MongoDB `findOneAndUpdate` as an atomic claim operation. The "find" and "claim" happen in a single atomic operation:

```
Workflow: Assign Next Available IP (L2)
1. Request: Pool ID, Server ID, MAC Address
2. Validate pool has available IPs (check used_addresses < total_addresses)
3. ATOMIC: findOneAndUpdate on ip_assignments collection:
   Filter:  { pool_id: X, status: "available" } (or find gap in sequence)
   Update:  { $set: { status: "active", assigned_to: {...}, mac_address: M } }
   Sort:    { ip_address: 1 } (get lowest available)
   Options: { returnDocument: "after" }
   → This atomically claims the IP. If two requests race, only one wins.
   → The loser gets null back and retries (max 3 attempts for contention).
4. On success: update pool counters (used_addresses++, utilization_percent)
5. Log to ip_assignment_history
6. Return assigned IP
```

```
Workflow: Assign L3 IP (Routed) — IPv4
1. Request: Pool ID, Server ID, Server's L2 IP
2. Validate pool availability
3. ATOMIC claim (same as above)
4. Get router device/cluster for pool
5. Add static route: {new_ip}/32 → {server_l2_ip}
6. Add ARP entry: {new_ip} → {server_mac}
7. Create firewall policies if requested
8. Update pool counters
9. Log all changes
10. Return assigned IP + route info

Workflow: Assign L3 IP (Routed) — IPv6
1. Request: Pool ID, Server ID, Server's L2 IP
2. ATOMIC claim from IPv6 pool
3. Add static route: {new_ip}/128 → {server_l2_ip} (or fe80:: link-local)
4. Add NDP entry: {new_ip} → {server_mac}
5. Create firewall policy (NO VIP, NO NAT — direct address matching)
6. Update pool counters
7. Log and return
```

```
Workflow: Release IP
1. Request: IP Address
2. Find ip_assignment document
3. If L3: Remove static route from router
4. If L3: Remove ARP/NDP entry
5. Remove associated firewall policies (and VIP if IPv4)
6. Create history record
7. Update ip_assignment status to 'released'
8. Update pool counters
9. Return success
```

> **Note:** Section 2.4 (IPAM API Endpoints) from the original plan is removed. All API endpoints are defined once in Section 14 (Complete API Reference) to avoid contract inconsistency.

---

## 3. Firewall Policy Management

### 3.1 Policy Types

**Inbound Policies (Internet → Server)**
- Control traffic from external sources
- Applied on edge firewall (FortiGate) or cluster
- IPv4: Uses destination NAT (VIP) for public IPs
- IPv6: NO VIP/NAT — direct address matching (IPv6 doesn't use NAT)

**Outbound Policies (Server → Internet)**
- Control what servers can access externally
- Applied on edge firewall or cluster
- IPv4: Uses source NAT (masquerade/SNAT)
- IPv6: No SNAT needed (global addresses are routable)

**Inter-Zone Policies**
- Control traffic between internal zones
- Server-to-server communication
- Management access

### 3.2 Firewall Collections (MongoDB)

```javascript
// ─── firewall_zones collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // Set if device is in HA cluster
    name: "wan",
    display_name: "WAN Zone",
    description: "External/Internet facing zone",
    interfaces: ["port1", "port3"],
    created_at: ISODate
}

// ─── firewall_address_objects collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // Set if device is in HA cluster
    name: "WebServer-01-IP",
    type: "host",                               // host | subnet | range | fqdn | geography
    ip_version: "ipv4",                         // REQUIRED
    address: "85.209.161.100",
    start_ip: null,
    end_ip: null,
    country_code: null,
    description: "Public IP of web-server-01",
    synced_to_device: true,
    created_at: ISODate
}

// ─── firewall_address_groups collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,
    name: "WebServers-Group",
    members: [ObjectId, ObjectId, ObjectId],
    description: "All public web server IPs",
    synced_to_device: true,
    created_at: ISODate
}

// ─── firewall_service_objects collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,
    name: "HTTPS",
    protocol: "tcp",                            // tcp | udp | icmp | icmpv6 | any
    port_start: 443,
    port_end: 443,
    icmp_type: null,
    description: "HTTPS traffic",
    is_system: true,
    synced_to_device: true,
    created_at: ISODate
}

// ─── firewall_policies collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // Config pushed to cluster management IP

    policy_id: 15,
    name: "Allow-Web-Server-01-HTTP",
    sequence: 15,

    ip_version: "ipv4",                         // "ipv4" | "ipv6" — REQUIRED

    direction: "inbound",                       // inbound | outbound | inter_zone
    source_zone: "wan",
    destination_zone: "internal",

    source_addresses: [ObjectId],
    destination_addresses: [ObjectId],
    services: [ObjectId, ObjectId],

    // NAT / VIP (IPv4 only — always null/false for IPv6)
    vip_id: ObjectId,                           // null for IPv6
    mapped_ip: "10.0.1.50",                     // null for IPv6
    mapped_port: 443,                           // null for IPv6
    nat_enabled: true,                          // ALWAYS false for IPv6
    nat_type: "destination",                    // source | destination | both | null (IPv6)

    action: "allow",                            // allow | deny | reject
    log_traffic: "all",                         // none | security | all
    schedule: null,
    enabled: true,

    comments: "Allow HTTPS to web-server-01",

    ip_assignment_id: ObjectId,
    server_id: "ims-server-uuid-123",

    synced_to_device: true,
    last_synced: ISODate,
    sync_error: null,

    created_at: ISODate,
    updated_at: ISODate,
    created_by: ObjectId
}

// ─── firewall_policy_history collection ───
{
    _id: ObjectId,
    policy_id: ObjectId,
    action: "created",                          // created | modified | deleted | enabled | disabled
    previous_state: { /* snapshot */ },
    new_state: { /* snapshot */ },
    changed_by: ObjectId,
    changed_at: ISODate,
    reason: "Manual policy update via NMS"
}

// ─── firewall_vips collection ───
// IPv4 ONLY — IPv6 does not use VIPs/NAT
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,
    name: "VIP-WebServer-01-HTTPS",
    ip_version: "ipv4",                         // Always ipv4 — this collection is IPv4-only
    external_ip: "85.209.161.100",
    external_port: 443,
    mapped_ip: "10.0.1.50",
    mapped_port: 443,
    protocol: "tcp",
    comment: "DNAT for web-server-01 HTTPS",
    synced_to_device: true,
    created_at: ISODate
}
```

### 3.3 Firewall Workflows

**Workflow: Create Inbound Policy for Server (IPv4)**
```
1. Request: Server IP, Allowed Services, Source restrictions
2. Find server's IP assignment in IPAM
3. Get edge firewall device/cluster
4. Create/update VIP for public IP → private IP mapping
5. Create address object for server
6. Create firewall policy:
   - Source: wan zone, requested sources
   - Destination: VIP
   - Services: requested services
   - Action: allow
   - nat_enabled: true, nat_type: "destination"
7. Push to device (via cluster management IP if clustered)
8. Verify policy was created
9. Update sync status
10. Log change
```

**Workflow: Create Inbound Policy for Server (IPv6)**
```
1. Request: Server IPv6, Allowed Services, Source restrictions
2. Find server's IP assignment in IPAM
3. Get edge firewall device/cluster
4. NO VIP created — IPv6 addresses are globally routable
5. Create address object for server IPv6 address
6. Create firewall policy:
   - Source: wan zone, requested sources
   - Destination: server IPv6 address object (NOT a VIP)
   - Services: requested services
   - Action: allow
   - nat_enabled: false, vip_id: null
7. Push to device
8. Verify and log
```

---

## 4. Routing Management

> Renamed from "Static Route Management". Now covers both managed static routes AND monitored dynamic routing protocols.

### 4.1 Scope Boundary (Explicit Design Decision)

| Protocol | NMS Role | Rationale |
|----------|----------|-----------|
| Static Routes | Full CRUD + sync | Deterministic, safe to automate |
| BGP | Monitor only | Complex, risk of routing blackholes if misconfigured |
| OSPF | Monitor only | Same risk as BGP |
| VRRP | Monitor only | Represented via HA Clusters (Section 1.3) |

NMS will **not** configure BGP or OSPF. It monitors their state for two purposes:
1. Dashboard visibility (BGP session health, learned prefix counts)
2. **Conflict detection** — before creating a static route, check if a BGP-learned route already covers that prefix

### 4.2 Static Route Collection (MongoDB)

```javascript
// ─── static_routes collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // Set if device is in HA cluster

    ip_version: "ipv4",                         // "ipv4" | "ipv6" — REQUIRED

    destination: "85.209.161.100/32",           // IPv4: /32, IPv6: /128 for host routes
    gateway: "10.0.1.50",
    interface_name: "port2",
    distance: 1,
    metric: 0,

    route_type: "host",                         // host | network | default

    ip_assignment_id: ObjectId,
    purpose: "L3 routing for web-server-01 public IP",

    synced_to_device: true,
    device_route_id: "*A1",
    last_synced: ISODate,
    sync_error: null,

    enabled: true,

    created_at: ISODate,
    updated_at: ISODate,
    created_by: ObjectId
}

// ─── route_history collection ───
{
    _id: ObjectId,
    route_id: ObjectId,
    device_id: ObjectId,
    action: "created",                          // created | modified | deleted
    destination: "85.209.161.100/32",
    gateway: "10.0.1.50",
    previous_state: null,
    new_state: { /* snapshot */ },
    changed_by: ObjectId,
    changed_at: ISODate,
    reason: "IPAM L3 IP assignment"
}
```

### 4.3 BGP Session Monitoring (Read-Only)

```javascript
// ─── bgp_sessions collection ───
// Polled periodically from devices, never written to devices
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,

    local_as: 65001,
    local_ip: "10.0.0.1",

    neighbor_ip: "10.0.0.2",
    neighbor_as: 65002,
    neighbor_description: "Transit - NTT",

    session_type: "ebgp",                       // ebgp | ibgp | ebgp_multihop
    address_family: "ipv4_unicast",             // ipv4_unicast | ipv6_unicast | l2vpn_evpn

    // Polled state
    state: "established",                       // idle | connect | active | opensent | openconfirm | established
    uptime_seconds: 1234567,
    prefixes_received: 842341,
    prefixes_advertised: 12,

    last_polled: ISODate,
    created_at: ISODate
}
```

### 4.4 BGP Conflict Check

**The problem with sampling:** BGP tables can exceed 900,000 routes. Sampling "top N prefixes" would miss the specific /32 or /24 that conflicts with a new static route.

**Solution: Targeted query, not full table dump.** Before creating a static route, the system calls `adapter->getBGPPrefixesForRange(cidr)` which asks the device: "do you have any BGP-learned routes that overlap this specific CIDR?" This is a targeted query that returns only matching prefixes, not the entire RIB.

```
BGP Conflict Check:
1. About to create static route for 85.209.161.100/32
2. Call adapter->getBGPPrefixesForRange("85.209.161.100/32") on the target device
3. Device CLI/API returns any BGP routes covering that prefix:
   - Exact match: 85.209.161.100/32 learned via BGP (conflict!)
   - Covering route: 85.209.161.0/24 learned via BGP (potential conflict)
4. If conflict found:
   - Log to audit: action "route.conflict.bgp_overlap"
   - Return warning to operator, do NOT proceed
   - Operator decides: proceed (static route will override) or abort
5. If no conflict: proceed with static route creation
```

### 4.5 Route Workflows

**Workflow: Add Host Route for L3 IP**
```
1. Triggered by IPAM L3 assignment
2. Input: L3 IP, Server's L2 gateway IP, ip_version
3. Run BGP conflict check (Section 4.4)
4. If no conflict: get router device/cluster from pool config
5. Create route: {l3_ip}/32 via {server_l2_ip} (IPv4) or /128 (IPv6)
6. Push to device via vendor adapter (with retry/backoff)
7. Verify route exists on device
8. Update static_routes document
9. Update ip_assignment with routing.static_route_added = true
10. Log to route_history
```

---

## 5. Neighbor Table Management (ARP + NDP)

> Renamed from "ARP Management". Now covers both ARP (IPv4) and NDP (IPv6 Neighbor Discovery Protocol).

### 5.1 Neighbor Collections (MongoDB)

```javascript
// ─── neighbor_entries collection ───
// (renamed from arp_entries — handles both ARP and NDP)
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,                       // Set if device is in HA cluster

    protocol: "arp",                            // "arp" (IPv4) | "ndp" (IPv6)
    ip_version: "ipv4",                         // "ipv4" | "ipv6" — matches protocol

    ip_address: "85.209.161.100",
    mac_address: "AA:BB:CC:11:22:33",
    interface_name: "port2",

    entry_type: "static",                       // static | dynamic_pinned

    ip_assignment_id: ObjectId,

    synced_to_device: true,
    device_entry_id: "*B3",
    last_synced: ISODate,

    enabled: true,
    created_at: ISODate,
    created_by: ObjectId
}

// ─── mac_address_registry collection ───
{
    _id: ObjectId,
    mac_address: "AA:BB:CC:11:22:33",
    vendor: "Dell Inc.",                        // OUI lookup
    device_type: "server",
    owner: {
        type: "server",
        id: "ims-server-uuid-123",
        name: "web-server-01"
    },
    notes: null,
    first_seen: ISODate,
    last_seen: ISODate
}

// ─── server_nics collection ───
// NMS owns the NETWORK context of each NIC (not the physical hardware — that's IMS).
// Synced from IMS on server.nic_change webhook. One document per physical NIC interface.
{
    _id: ObjectId,
    ims_server_id: "ims-server-uuid-123",       // IMS server UUID (foreign key to IMS)
    server_name: "web-server-01",               // Denormalised for queries
    nic_name: "eth0",                           // Interface name as seen by OS
    mac_address: "AA:BB:CC:11:22:33",          // Synced from IMS
    nic_index: 0,                               // Physical NIC slot index (from IMS)

    // Network connectivity (owned by NMS)
    connected_to: {
        device_id: ObjectId,                    // Switch or patch panel this NIC plugs into
        device_name: "Core-SW-01",
        port_name: "ge-0/0/15",
        cable_id: ObjectId                      // Cable in NMS cables collection
    },

    // L2 network config
    vlan_id: 100,
    vlan_name: "management",
    access_mode: "access",                      // access | trunk

    // L3 IP assignments (references ip_assignments collection)
    ip_assignments: [
        {
            assignment_id: ObjectId,
            ip_address: "10.0.1.50",
            ip_version: "ipv4",
            prefix_length: 24,
            gateway: "10.0.1.1",
            assignment_type: "l2"               // l2 | l3
        },
        {
            assignment_id: ObjectId,
            ip_address: "2001:db8::50",
            ip_version: "ipv6",
            prefix_length: 64,
            gateway: "2001:db8::1",
            assignment_type: "l2"
        }
    ],

    // Bonding / LAG
    is_bond_member: false,
    bond_master: null,                          // NIC name of bond master if applicable

    status: "active",                           // active | down | unknown
    last_synced: ISODate,                       // When NMS last confirmed via IMS webhook
    created_at: ISODate,
    updated_at: ISODate
}
```

**NIC indexes:**
```javascript
db.server_nics.createIndex({ "ims_server_id": 1 });
db.server_nics.createIndex({ "mac_address": 1 }, { unique: true });
db.server_nics.createIndex({ "connected_to.device_id": 1 });
db.server_nics.createIndex({ "connected_to.cable_id": 1 });
db.server_nics.createIndex({ "vlan_id": 1 });
```

---

## 6. Infrastructure Topology & Physical Mapping

This is the **core architectural feature** of NMS. The topology system models the complete physical and logical layout of all infrastructure across all sites in a 3D spatial model: building → floor → room → row → rack → U-position.

### 6.1 Physical Infrastructure Model

```
Sites (Data Centers)
    └── Buildings / Floors / Rooms
        └── Rows
            └── Racks
                └── Devices (at specific U positions)
                    └── Ports
                        └── Cables → connecting to other Ports
                            └── Through Patch Panels (front/rear port pairs)
```

### 6.2 Topology Collections (MongoDB)

```javascript
// ─── sites collection ───
{
    _id: ObjectId,
    name: "DC1-Amsterdam",
    code: "DC1",
    type: "datacenter",                         // datacenter | colocation | pop | office
    address: {
        street: "Keizersgracht 123",
        city: "Amsterdam",
        country: "NL",
        postal_code: "1015 AA",
        coordinates: { lat: 52.3676, lng: 4.9041 }   // For geospatial queries + site map
    },
    provider: "Equinix",
    contract_id: "EQ-AMS-2024-001",

    stats: {
        total_racks: 12,
        total_devices: 87,
        total_ports: 1240,
        active_connections: 956
    },

    uplinks: [
        {
            type: "transit",                    // transit | peering | cross_connect | dark_fiber
            provider: "NTT",
            bandwidth_mbps: 10000,
            port_on_device_id: ObjectId,
            port_name: "port1",
            circuit_id: "NTT-AMS-0042",
            description: "Primary transit"
        },
        {
            type: "cross_connect",
            provider: "Equinix Fabric",
            bandwidth_mbps: 10000,
            target_site_id: ObjectId,
            target_site_name: "DC2-Frankfurt",
            circuit_id: "EQX-CC-0012",
            description: "Inter-DC link to Frankfurt"
        }
    ],

    contacts: [
        { name: "NOC", email: "noc@example.com", phone: "+31..." }
    ],

    notes: "Primary production data center",
    status: "active",                           // active | planned | decommissioning
    created_at: ISODate
}

// ─── racks collection ───
{
    _id: ObjectId,
    site_id: ObjectId,
    name: "R-A2-07",
    label: "Row A, Position 7",

    location: {
        building: "A",
        floor: "2",
        room: "Server Room 1",
        row: "A",
        position: 7
    },

    specs: {
        total_units: 42,
        usable_units: 40,
        width_inches: 19,
        depth_inches: 42,
        max_power_watts: 8000
    },

    // What's installed (auto-populated from devices)
    installed_devices: [
        {
            device_id: ObjectId,
            device_name: "Edge-FW-01",
            device_type: "firewall",
            vendor: "fortigate",
            rack_unit_start: 42,
            rack_unit_end: 42,
            side: "front",
            power_draw_watts: 150
        },
        {
            device_id: ObjectId,
            device_name: "PP-A2-01",
            device_type: "patch_panel",         // Patch panels are first-class devices
            vendor: "generic",
            rack_unit_start: 39,
            rack_unit_end: 39,
            side: "front",
            power_draw_watts: 0                 // Passive device
        },
        {
            device_id: ObjectId,
            device_name: "web-server-01",
            device_type: "server",
            vendor: "dell",
            rack_unit_start: 38,
            rack_unit_end: 38,
            side: "front",
            power_draw_watts: 500,
            ims_server_id: "ims-uuid-123"       // Cross-reference to IMS
        }
    ],

    utilization: {
        used_units: 15,
        available_units: 25,
        power_used_watts: 4500,
        power_available_watts: 3500
    },

    notes: "Primary network rack for row A",
    status: "active",
    created_at: ISODate
}

// ─── Patch Panels as First-Class Devices ───
// Patch panels are registered in the `devices` collection with role: "patch_panel"
// Each port has EXPLICIT front/rear mapping (Port 1 front wires to Port 1 rear)
// {
//     role: "patch_panel",
//     vendor: "generic",
//     status: "online",                        // Always online (passive device)
//     ports: [
//         {
//             name: "PP-01",                   // Port pair identifier
//             type: "rj45",
//             front_label: "F-01",             // Label on front side
//             rear_label: "R-01",              // Label on rear side
//             // Front connection (e.g., from server NIC)
//             front_connection: {
//                 connected_to_device_id: ObjectId,
//                 connected_to_device_name: "web-server-01",
//                 connected_to_port: "eth0",
//                 cable_id: "C-A2-38-001"
//             },
//             // Rear connection (e.g., to switch port)
//             rear_connection: {
//                 connected_to_device_id: ObjectId,
//                 connected_to_device_name: "Core-SW-01",
//                 connected_to_port: "ge-0/0/15",
//                 cable_id: "C-A2-PP-015"
//             }
//         }
//     ]
//     // No credentials needed — passive device
// }

// ─── cables collection ───
{
    _id: ObjectId,
    cable_id: "C-A2-07-001",                    // Physical label on cable

    endpoint_a: {
        site_id: ObjectId,
        rack_id: ObjectId,
        rack_name: "R-A2-07",
        device_id: ObjectId,
        device_name: "Edge-FW-01",
        port_name: "port2"
    },

    endpoint_b: {
        site_id: ObjectId,
        rack_id: ObjectId,
        rack_name: "R-A2-07",
        device_id: ObjectId,
        device_name: "PP-A2-01",                // Can connect to patch panel
        port_name: "F-12"                       // Front port label
    },

    cable_type: "cat6a",                        // cat5e | cat6 | cat6a | fiber_sm | fiber_mm | dac
    length_meters: 3,
    color: "yellow",
    connector_a: "RJ45",                        // RJ45 | LC | SC | MPO | SFP+
    connector_b: "RJ45",

    status: "active",                           // active | spare | faulty | decommissioned
    installed_at: ISODate,
    installed_by: ObjectId,
    tested: true,
    test_result: "pass",                        // pass | fail | degraded

    notes: null,
    created_at: ISODate
}

// ─── connectivity_paths collection ───
// Pre-computed end-to-end cable paths through patch panels
// Solves: MongoDB $graphLookup is slow/complex for deep paths
// Updated when any cable is created/modified/deleted
{
    _id: ObjectId,

    source: {
        device_id: ObjectId,
        device_name: "web-server-01",
        port_name: "eth0"
    },
    destination: {
        device_id: ObjectId,
        device_name: "Core-SW-01",
        port_name: "ge-0/0/15"
    },

    // Ordered hop-by-hop traversal
    hops: [
        { device_id: ObjectId, device_name: "web-server-01",  port_out: "eth0",     cable_id: "C-A2-38-001" },
        { device_id: ObjectId, device_name: "PP-A2-01",       port_in: "F-15",  port_out: "R-15",   cable_id: "C-A2-PP-015" },
        { device_id: ObjectId, device_name: "Core-SW-01",     port_in: "ge-0/0/15", cable_id: null }
    ],

    hop_count: 2,
    total_cable_length_meters: 8,

    // Fast impact analysis: which paths use a given cable?
    cable_ids: ["C-A2-38-001", "C-A2-PP-015"],

    computed_at: ISODate,
    valid: true                                 // false = needs recomputation (cable changed)
}

// ─── topology_views collection ───
{
    _id: ObjectId,
    name: "DC1 Network Overview",
    description: "Logical network topology for DC1",
    view_type: "logical",                       // physical | logical | layer3 | site_map

    scope: {
        type: "site",                           // site | rack | global | custom
        site_id: ObjectId
    },

    node_positions: [
        {
            device_id: ObjectId,
            x: 100,
            y: 200,
            icon: "fortigate",
            color: "#FF6B6B",
            label: "Edge-FW-01"
        }
    ],

    layers: [
        { name: "Edge", visible: true, devices: [ObjectId] },
        { name: "Core", visible: true, devices: [ObjectId, ObjectId] },
        { name: "Access", visible: true, devices: [ObjectId, ObjectId, ObjectId] }
    ],

    created_at: ISODate,
    created_by: ObjectId,
    updated_at: ISODate
}

// ─── topology_snapshots collection ───
{
    _id: ObjectId,
    name: "Pre-Migration Snapshot",
    description: "Topology state before DC2 migration",
    snapshot_data: {
        sites: [ /* full site documents */ ],
        racks: [ /* full rack documents */ ],
        devices: [ /* full device documents with ports */ ],
        cables: [ /* full cable documents */ ]
    },
    created_at: ISODate,
    created_by: ObjectId
}
```

### 6.3 Path Materialization & Invalidation

When any cable is created, updated, or deleted:
1. Find all `connectivity_paths` where `cable_ids` contains that cable's ID
2. Mark them `valid: false`
3. Queue background recomputation job for each invalidated path
4. Recomputation walks the graph from endpoints outward (max depth 6 hops)

**Read path** (happy path): Single indexed query on `connectivity_paths` — no graph traversal needed.

**Fallback**: For on-demand queries before recomputation runs, use `$graphLookup` with `maxDepth: 6` on the `cables` collection. Safe for DC environments where paths are physically bounded.

### 6.4 Topology Discovery Process

```
1. Start with registered devices
2. For each device:
   a. Get interface list via vendor adapter
   b. Get neighbor discovery data (CDP, LLDP, MikroTik Neighbor)
   c. Get ARP/NDP table
   d. Get MAC address table (switches)
3. Build/update device port list
4. Match discovered neighbors to registered devices
5. Create/update cable records for discovered connections
6. Flag unknown neighbors for manual review
7. Update rack installed_devices from device location data
8. Recompute affected connectivity_paths
9. Calculate site stats
10. Return topology data for visualization
```

### 6.5 Topology API Response Format

```json
{
    "sites": [
        {
            "id": "site-uuid",
            "name": "DC1-Amsterdam",
            "code": "DC1",
            "coordinates": { "lat": 52.3676, "lng": 4.9041 },
            "stats": { "devices": 87, "racks": 12 },
            "uplinks": [
                {
                    "type": "cross_connect",
                    "target_site": "DC2-Frankfurt",
                    "bandwidth": 10000,
                    "status": "up"
                }
            ]
        }
    ],
    "nodes": [
        {
            "id": "device-uuid",
            "type": "firewall",
            "name": "Edge-FW-01",
            "ip": "192.168.1.1",
            "status": "online",
            "cluster": "DC1-FW-Cluster-01",
            "cluster_role": "primary",
            "drift_status": "clean",
            "site": "DC1",
            "rack": "R-A2-07",
            "ports": [
                {"name": "port1", "ip": "85.209.161.1/24", "status": "up", "connected_to": "ISP-1"},
                {"name": "port2", "ip": "10.0.0.1/24", "status": "up", "connected_to": "Core-SW-01:xe-1/0/0"}
            ]
        }
    ],
    "links": [
        {
            "id": "cable-uuid",
            "source": "device-uuid-1",
            "source_port": "port2",
            "target": "device-uuid-2",
            "target_port": "xe-1/0/0",
            "cable_type": "fiber_sm",
            "status": "up",
            "bandwidth": 10000,
            "via_patch_panels": ["PP-A2-01"]
        }
    ]
}
```

---

## 7. Zabbix Monitoring Integration

NMS delegates **all** monitoring (CPU, memory, traffic, uptime, alerting) to Zabbix and pulls data via the Zabbix API when needed for display. NMS does not duplicate Zabbix's monitoring storage.

### 7.1 Zabbix API Integration

**Base URL:** `https://{zabbix_server}/api_jsonrpc.php`

**Key API Methods Used:**
| Function | Method | Purpose |
|----------|--------|---------|
| Get hosts | `host.get` | Map NMS devices to Zabbix hosts |
| Get items | `item.get` | CPU, memory, interface traffic |
| Get triggers | `trigger.get` | Active alerts/problems |
| Get history | `history.get` | Historical metric data |
| Get graphs | `graph.get` | Pre-built Zabbix graphs |
| Get events | `event.get` | Event log |

### 7.2 NMS-Zabbix Device Mapping

Stored in devices collection as `zabbix: { host_id, host_name, last_synced, auto_import }` (see Section 1.3).

### 7.3 Caching Strategy (Redis — Required)

NMS queries Zabbix on-demand and caches briefly in Redis:
- **Device health data**: 60s TTL (CPU, memory, uptime)
- **Interface traffic**: 60s TTL (bps in/out per interface)
- **Active problems**: 30s TTL (alerts affecting NMS-managed devices)
- **Availability**: 30s TTL (online/offline confirmation)

Redis is **required** (not optional) for this caching, for the JWT blocklist (Section 11), for rate limiting, and for idempotency key storage.

---

## 8. Compliance & Audit Logs

### 8.1 Audit Collections (MongoDB)

```javascript
// ─── audit_logs collection ───
{
    _id: ObjectId,

    timestamp: ISODate,

    // Who
    user_id: ObjectId,
    username: "admin",
    user_ip: "10.0.0.50",
    user_agent: "Mozilla/5.0...",
    token_type: "user",                         // "user" | "m2m" (machine-to-machine)

    // What
    action: "ip.assign",                        // ip.assign | device.update | policy.create |
                                                // drift.detected | drift.resolved |
                                                // credential.accessed | route.conflict.bgp_overlap
    resource_type: "ip",                        // ip | device | policy | route | neighbor | cluster | drift | credential
    resource_id: "85.209.161.100",
    resource_name: "IP 85.209.161.100",

    // Details
    request_data: { /* input parameters */ },
    response_data: { /* result */ },
    changes: {
        before: { /* relevant fields before */ },
        after: { /* relevant fields after */ }
    },

    // Idempotency
    idempotency_key: null,                      // Set when X-Idempotency-Key header is used

    // Result
    status: "success",                          // success | failure | partial
    error_message: null,

    // Context
    session_id: "sess-uuid",
    api_endpoint: "/api/ipam/assignments",
    http_method: "POST",

    // TTL index - auto-expire after retention period
    expires_at: ISODate                         // e.g., +365 days
}

// ─── device_config_changes collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,

    change_type: "route.add",
    config_section: "routing",

    previous_value: null,
    new_value: "ip route 85.209.161.100/32 10.0.1.50",
    diff: "+ ip route 85.209.161.100/32 10.0.1.50",

    source: "nms",                              // nms | manual | drift_pull | unknown
    triggered_by: ObjectId,

    verified: true,
    verification_time: ISODate,

    detected_at: ISODate
}
```

---

## 9. VPN Management

### 9.1 VPN Collections (MongoDB)

```javascript
// ─── vpn_gateways collection ───
{
    _id: ObjectId,
    device_id: ObjectId,
    cluster_id: ObjectId,
    name: "DC1-VPN-Gateway",

    gateway_type: "ipsec",                      // ipsec | openvpn | wireguard | l2tp
    public_ip: "85.209.161.2",
    public_port: 500,

    auth_type: "psk",                           // psk | certificate | radius | ldap

    // PSK stored in Vault — NOT in MongoDB
    vault: {
        provider: "hashicorp_vault",
        path: "nms/vpn/dc1-gw/psk",
        version: 1
    },

    client_ip_pool_id: ObjectId,
    dns_servers: ["8.8.8.8", "8.8.4.4"],

    enabled: true,
    created_at: ISODate
}

// ─── vpn_tunnels collection ───
{
    _id: ObjectId,
    name: "DC1-to-DC2-Tunnel",

    local_gateway_id: ObjectId,
    local_subnets: ["10.0.0.0/16"],

    remote_gateway_ip: "85.209.162.2",
    remote_id: "dc2-vpn@example.com",
    remote_subnets: ["10.1.0.0/16"],

    // Phase 1 (IKE)
    ike_version: "2",
    ike_encryption: "aes256",
    ike_hash: "sha256",
    ike_dh_group: 14,
    ike_lifetime: 86400,

    // Phase 2 (ESP)
    esp_encryption: "aes256",
    esp_hash: "sha256",
    esp_pfs_group: 14,
    esp_lifetime: 3600,

    // PSK in Vault
    vault: {
        provider: "hashicorp_vault",
        path: "nms/vpn/dc1-to-dc2/psk",
        version: 1
    },

    enabled: true,
    status: "up",                               // up | down | establishing | unknown
    last_status_check: ISODate,

    synced_to_device: true,
    created_at: ISODate
}

// ─── vpn_users collection ───
{
    _id: ObjectId,
    gateway_id: ObjectId,

    username: "john.doe",
    password_hash: "$argon2id$...",              // Hashed, not encrypted
    certificate_cn: null,

    assigned_ip: "10.10.0.50",
    allowed_subnets: ["10.0.0.0/16"],

    bandwidth_limit_kbps: null,
    concurrent_connections: 1,

    enabled: true,
    expires_at: null,

    last_connected: ISODate,
    total_bytes_in: 1234567890,
    total_bytes_out: 9876543210,

    created_at: ISODate,
    created_by: ObjectId
}
```

---

## 10. IMS Integration

### 10.1 Integration Architecture

```
┌─────────────────────┐                ┌─────────────────────┐
│         IMS         │                │         NMS         │
│                     │   Webhooks +   │                     │
│  Server Build       │    REST API    │   IP Assign         │
│  Server Deploy      │◄──────────────►│   Route Config      │
│  Inventory Mgmt     │                │   Firewall          │
│  Hardware Info       │                │   Topology          │
│  OS / BIOS          │                │   Cable Tracking    │
│  NIC / MAC          │                │   Drift Detection   │
└─────────────────────┘                └─────────────────────┘
         │                                      │
         └───────────────┬──────────────────────┘
                         │
                   Shared Auth (JWT)
                   Combined = Complete
                   hardware + network picture
```

**What IMS + NMS Know Together:**
> "Server `web-server-01` (Dell R640, IMS UUID: ims-123) is at DC1-Amsterdam, Building A, Floor 2, Rack R-A2-07, U38. Its NIC `eth0` (MAC: AA:BB:CC:11:22:33) is connected via cable C-A2-38-001 through patch panel PP-A2-01 (port F-15 → R-15) to Core-SW-01 port ge-0/0/15. It has management IP 10.0.1.50 (L2, VLAN 100) and public IP 85.209.161.100 (L3, /32 route via 10.0.1.50), protected by firewall policy #15 on DC1-FW-Cluster-01."

This is the complete hardware-to-network picture that neither system provides alone.

**Ownership Boundaries:**
| Aspect | Owner |
|--------|-------|
| Server hardware, BIOS, OS, builds | IMS |
| NIC physical inventory (model, PCI slot, bond config) | IMS |
| NIC MAC addresses | IMS (synced to NMS on `server.nic_change` event) |
| NIC network context (switch port, VLAN, IP assignment, cable) | NMS (`server_nics` collection) |
| Rack placement, U-position | IMS (synced to NMS) |
| Network identity: IPs, routes, firewall rules | NMS |
| Cable tracking, port-to-port connections | NMS |
| Topology visualization | NMS |
| Monitoring / alerting | Zabbix (queried by both) |
| Ticketing / work queue | IMS (NMS creates tickets via M2M API) |
| Users, roles, permissions | IMS (NMS reads JWT claims only) |

### 10.2 Bidirectional Webhook Events

**IMS → NMS Events:**
| Event | Trigger | NMS Action |
|-------|---------|------------|
| `server.nic_change` | NIC replaced/added | Update MAC in IPAM + ARP/NDP entries |
| `server.reboot` | Server rebooted | (Informational — no NMS action) |

**NMS → IMS Events:**
| Event | Trigger | IMS Action |
|-------|---------|------------|
| `ip.changed` | IP reassigned/rotated | Update server's IP references |
| `drift.detected` | Out-of-band change found | (Informational for IMS audit log) |

### 10.3 Integration Endpoints

**NMS Endpoints for IMS:**
```
GET  /api/integration/ims/server/{server_id}/network
GET  /api/integration/ims/server/{server_id}/connections
```

**IMS Endpoints Called by NMS:**
```
GET  {IMS_URL}/api/server/{server_id}                    - Verify server exists
PUT  {IMS_URL}/api/server/{server_id}/network            - Update server network info
GET  {IMS_URL}/api/server/{server_id}/hardware/nic       - Get NIC/MAC info (list all NICs)
POST {IMS_URL}/api/webhooks/nms                          - Send NMS events to IMS

// Ticketing (NMS → IMS via M2M)
POST {IMS_URL}/api/tickets                              - Create NMS ticket in IMS system
PUT  {IMS_URL}/api/tickets/{ticket_id}                  - Update ticket (resolve, comment, close)
GET  {IMS_URL}/api/tickets/{ticket_id}                  - Read ticket state
```

### 10.4 Shared Ticketing System

NMS does **not** have its own ticketing system. All human-actionable items are created as tickets in the IMS ticket system via M2M API calls. Engineers work from a single queue.

**NMS Ticket Types (registered in IMS):**

| NMS Event | IMS Ticket Type | Trigger | Auto-close Trigger |
|-----------|-----------------|---------|-------------------|
| Drift detected, resolution needs approval | `nms_drift` | Drift detected with `requires_approval: true` | Drift resolved or dismissed |
| Device unreachable for >15 min | `nms_device_unreachable` | Circuit breaker open + health check failing | Device comes back online |

**Ticket creation (M2M call from NMS):**

```json
POST {IMS_URL}/api/tickets
{
    "type": "nms_drift",
    "title": "Configuration drift detected: DC1-Edge-Router-01",
    "body": "Firewall rule added out-of-band on DC1-Edge-Router-01. Manual approval required before resolving.",
    "priority": "medium",
    "source_system": "nms",
    "source_ref": {
        "drift_id": "drift-uuid",
        "device_id": "router-uuid"
    },
    "assigned_group": "network-ops"
}
```

---

## 11. Authentication & Authorization

> Expanded from the original minimal auth section. Addresses: key rotation, token revocation, M2M tokens, secrets-backed key storage.

### 11.1 JWT Token Strategy

**Token Types:**
| Type | Audience (`aud`) | Lifetime | Use Case |
|------|----------|----------|----------|
| User Access Token | `nms` | 15 min | API requests from frontend |
| User Refresh Token | `nms-refresh` | 7 days | One-time use, rotated on refresh |
| M2M Token (IMS→NMS) | `nms-m2m` | 1 hour | IMS calling NMS integration APIs |
| M2M Token (NMS→IMS) | `ims-m2m` | 1 hour | NMS calling IMS webhook/server APIs |

**Token Structure:**
```json
{
    "iss": "ims-nms-auth",
    "aud": "nms",
    "sub": "user-uuid",
    "exp": 1709313000,
    "iat": 1709312100,
    "jti": "unique-token-id",
    "type": "access",
    "roles": ["admin"],
    "permissions": ["nms.device.read", "nms.ipam.write"]
}
```

### 11.2 HMAC Key Management

**Signing Key Location:** The HS256 signing key is stored in HashiCorp Vault at `nms/auth/jwt-signing-key` — **never** in `.env` or source code.

**Key Rotation Procedure (dual-key window):**
```
1. Generate new key (Key B) in Vault
2. Configure NMS to ACCEPT tokens signed by either Key A (old) or Key B (new)
3. Switch NMS to SIGN new tokens with Key B
4. Wait for Key A's longest-lived token to expire (max 7 days for refresh tokens)
5. Remove Key A from Vault
6. Rotation complete — downtime: zero
```

### 11.3 Token Revocation (Redis Blocklist)

On logout or credential compromise, token's `jti` (unique ID) is added to a Redis blocklist:

```
Key:    blocklist:{jti}
Value:  1
TTL:    remaining lifetime of the token (auto-expires when token would have expired)
```

Every API request checks Redis blocklist before processing. Redis is **required** for this.

**Bulk Revocation:** To revoke all tokens for a user (e.g., password change), store `user:{user_id}:tokens_valid_after` in Redis with the current timestamp. Any token with `iat` before this timestamp is rejected.

### 11.4 Machine-to-Machine (M2M) Authentication

IMS and NMS communicate via M2M tokens:

```
IMS → NMS (integration request):
  1. IMS requests M2M token from shared auth service (or generates locally with shared key)
  2. Token has: aud: "nms-m2m", sub: "ims-service", permissions: ["nms.integration.*"]
  3. NMS validates: aud == "nms-m2m" AND issuer is trusted AND not in blocklist
  4. NMS checks M2M-specific permission set (more restrictive than admin)

NMS → IMS (webhook event):
  1. NMS generates M2M token with: aud: "ims-m2m"
  2. Sends as Authorization header in webhook call
  3. IMS validates aud == "ims-m2m"
```

### 11.5 RBAC — Shared with IMS

**Architecture:** IMS is the single source of truth for all users, roles, and permissions across both systems. NMS does **not** maintain its own users/roles database. At login, IMS issues a JWT containing the full permission set for both systems. NMS validates the JWT signature and reads the `permissions` claim locally — no RBAC DB lookups in NMS.

```
IMS owns:                          NMS does:
  users collection                   Validate JWT (signature + expiry)
  roles collection                   Check permissions[] claim
  permissions collection             Check Redis blocklist (revocation)
  User management UI                 Reject if permission absent
  Role assignment UI
```

**Why this works:** The JWT already carries `permissions: ["nms.device.read", "ims.server.read", ...]` as a claim. Since NMS validates the token locally (shared signing key from Vault), it can gate every endpoint without querying IMS. No extra network hop per request.

**NMS-specific permission set (registered in IMS permissions table):**

```javascript
// Permissions follow: nms.{resource}.{action} pattern
// These are defined as records in IMS's permissions collection
"nms.device.read"         "nms.device.write"         "nms.device.delete"
"nms.device.execute"      // Safe command execution (allowlisted only)
"nms.cluster.read"        "nms.cluster.write"
"nms.ipam.read"           "nms.ipam.write"           "nms.ipam.assign"
"nms.firewall.read"       "nms.firewall.write"       "nms.firewall.sync"
"nms.route.read"          "nms.route.write"           "nms.route.sync"
"nms.topology.read"       "nms.topology.discover"
"nms.drift.read"          "nms.drift.resolve"
"nms.audit.read"          "nms.audit.export"
"nms.vpn.read"            "nms.vpn.write"
"nms.settings.read"       "nms.settings.write"
"nms.nic.read"            "nms.nic.write"
```

**NMS RBACMiddleware.php** only reads the JWT claim — it does not query MongoDB for permissions:

```php
// RBACMiddleware.php (simplified)
$permissions = $jwt->claims->permissions; // already in token
if (!in_array($requiredPermission, $permissions)) {
    return Response::forbidden('Insufficient permissions');
}
```

**User management UI** lives entirely in IMS. NMS has no `/settings/users` or `/settings/roles` pages. NMS settings only covers: Zabbix config, Vault health, vendor integrations.

---

## 12. Complete Database Schema (MongoDB)

*All collections defined in preceding sections. Summary:*

| Category | Collections |
|----------|-------------|
| **Devices** | devices, device_credentials, device_backups |
| **HA Clusters** | device_clusters, cluster_events |
| **Drift** | config_drift_log |
| **IPAM** | ip_blocks, ip_pools, ip_subnets, ip_assignments, ip_assignment_history, ip_reservations |
| **Firewall** | firewall_zones, firewall_address_objects, firewall_address_groups, firewall_service_objects, firewall_policies, firewall_policy_history, firewall_vips |
| **Routing** | static_routes, route_history, bgp_sessions |
| **Neighbor** | neighbor_entries, mac_address_registry |
| **Physical Infra** | sites, racks, cables |
| **Topology** | connectivity_paths, topology_views, topology_snapshots |
| **Audit** | audit_logs, device_config_changes |
| **VPN** | vpn_gateways, vpn_tunnels, vpn_users |
| **NICs** | server_nics |
| **Auth** | *(none — owned by IMS; NMS reads JWT claims only)* |

**MongoDB Indexes:**
```javascript
// Devices
db.devices.createIndex({ "ip_address": 1 }, { unique: true });
db.devices.createIndex({ "vendor": 1, "status": 1 });
db.devices.createIndex({ "location.site_id": 1 });
db.devices.createIndex({ "location.rack_id": 1 });
db.devices.createIndex({ "cluster_id": 1 });
db.devices.createIndex({ "drift.status": 1 });

// Clusters
db.device_clusters.createIndex({ "management_ip": 1 }, { unique: true });
db.device_clusters.createIndex({ "members.device_id": 1 });

// Drift
db.config_drift_log.createIndex({ "device_id": 1, "status": 1 });
db.config_drift_log.createIndex({ "detected_at": -1 });

// IPAM
db.ip_assignments.createIndex({ "ip_address": 1 }, { unique: true });
db.ip_assignments.createIndex({ "pool_id": 1, "status": 1 });
db.ip_assignments.createIndex({ "assigned_to.type": 1, "assigned_to.id": 1 });
db.ip_assignments.createIndex({ "ip_version": 1 });
db.ip_pools.createIndex({ "network": 1 });
db.ip_pools.createIndex({ "site_id": 1, "ip_version": 1 });
db.ip_blocks.createIndex({ "ip_version": 1 });

// Firewall
db.firewall_policies.createIndex({ "device_id": 1, "sequence": 1 });
db.firewall_policies.createIndex({ "cluster_id": 1, "sequence": 1 });
db.firewall_policies.createIndex({ "ip_version": 1 });

// Routes
db.static_routes.createIndex({ "device_id": 1, "destination": 1 }, { unique: true });
db.static_routes.createIndex({ "cluster_id": 1, "destination": 1 });
db.static_routes.createIndex({ "ip_version": 1 });
db.bgp_sessions.createIndex({ "device_id": 1 });

// Neighbor entries
db.neighbor_entries.createIndex({ "device_id": 1, "ip_address": 1 }, { unique: true });
db.neighbor_entries.createIndex({ "protocol": 1 });

// Physical
db.sites.createIndex({ "code": 1 }, { unique: true });
db.sites.createIndex({ "address.coordinates": "2dsphere" });     // Geospatial
db.racks.createIndex({ "site_id": 1 });
db.cables.createIndex({ "cable_id": 1 }, { unique: true });
db.cables.createIndex({ "endpoint_a.device_id": 1 });
db.cables.createIndex({ "endpoint_b.device_id": 1 });

// Connectivity paths
db.connectivity_paths.createIndex({ "source.device_id": 1, "source.port_name": 1 });
db.connectivity_paths.createIndex({ "destination.device_id": 1 });
db.connectivity_paths.createIndex({ "cable_ids": 1 });
db.connectivity_paths.createIndex({ "valid": 1 });

// Audit
db.audit_logs.createIndex({ "timestamp": -1 });
db.audit_logs.createIndex({ "user_id": 1, "timestamp": -1 });
db.audit_logs.createIndex({ "resource_type": 1, "resource_id": 1 });
db.audit_logs.createIndex({ "expires_at": 1 }, { expireAfterSeconds: 0 });
db.audit_logs.createIndex({ "idempotency_key": 1 }, { sparse: true });
```

---

## 13. Complete API Reference

> **Single source of truth.** All endpoints defined here. No inline endpoint lists in other sections.
> All endpoints use REST-style URLs: `/api/{resource}s` (plural nouns, not verbs).

### Authentication APIs
```
POST   /api/auth/login               - Login → returns access + refresh tokens
POST   /api/auth/refresh             - Refresh token (one-time use, rotated)
POST   /api/auth/logout              - Revoke tokens (adds jti to Redis blocklist)
GET    /api/auth/me                  - Get current user info
POST   /api/auth/m2m/token           - Issue M2M token (for IMS integration)
```

### Device APIs
```
GET    /api/devices                  - List all devices (filterable by vendor, status, site)
POST   /api/devices                  - Register new device
GET    /api/devices/{id}             - Get device details
PUT    /api/devices/{id}             - Update device
DELETE /api/devices/{id}             - Remove device
GET    /api/devices/{id}/status      - Check device connectivity
GET    /api/devices/{id}/ports       - List ports with connections
POST   /api/devices/{id}/backup      - Create config backup
GET    /api/devices/{id}/backups     - List backups
POST   /api/devices/{id}/execute     - Execute safe command (allowlisted, requires nms.device.execute)
```

### Cluster APIs
```
GET    /api/clusters                 - List all HA clusters
POST   /api/clusters                 - Create cluster
GET    /api/clusters/{id}            - Cluster details (members, failover history)
PUT    /api/clusters/{id}            - Update cluster
GET    /api/clusters/{id}/status     - Live health check of all member nodes
GET    /api/clusters/{id}/events     - Failover event history
POST   /api/clusters/{id}/failover   - Manual failover trigger
```

### Drift APIs
```
GET    /api/drift                    - All open drift items across all devices
GET    /api/devices/{id}/drift       - Drift status and open diffs for a device
POST   /api/devices/{id}/drift/scan  - Force immediate drift scan
POST   /api/drift/{id}/resolve       - Resolve drift: push | pull | ignore
```

### IPAM APIs
```
# Blocks
GET    /api/ipam/blocks              - List IP blocks
POST   /api/ipam/blocks              - Create block
GET    /api/ipam/blocks/{id}         - Block details

# Pools
GET    /api/ipam/pools               - List pools (filterable by ip_version, site_id)
POST   /api/ipam/pools               - Create pool
GET    /api/ipam/pools/{id}          - Pool details
PUT    /api/ipam/pools/{id}          - Update pool
DELETE /api/ipam/pools/{id}          - Delete pool
GET    /api/ipam/pools/{id}/available - List available IPs
GET    /api/ipam/pools/{id}/usage    - Utilization stats

# Assignments
GET    /api/ipam/assignments         - List all assignments
POST   /api/ipam/assignments         - Create assignment (atomic claim)
POST   /api/ipam/assignments/next    - Assign next available (atomic)
GET    /api/ipam/assignments/{ip}    - Get assignment details
PUT    /api/ipam/assignments/{ip}    - Update assignment
DELETE /api/ipam/assignments/{ip}    - Release IP
GET    /api/ipam/assignments/{ip}/history - Assignment history

# Search & Utilities
GET    /api/ipam/search              - Search IPs/assignments
POST   /api/ipam/calculate           - Subnet calculator
POST   /api/ipam/validate            - Validate IP/CIDR
```

### Firewall APIs
```
GET    /api/firewall/policies                     - List policies (filterable by ip_version, cluster_id)
POST   /api/firewall/policies                     - Create policy
GET    /api/firewall/policies/{id}                - Get policy
PUT    /api/firewall/policies/{id}                - Update policy
DELETE /api/firewall/policies/{id}                - Delete policy
POST   /api/firewall/policies/{id}/sync           - Sync to device
POST   /api/firewall/policies/reorder             - Reorder policies

GET    /api/firewall/addresses                    - List address objects
POST   /api/firewall/addresses                    - Create address
GET    /api/firewall/services                     - List services
POST   /api/firewall/services                     - Create service

GET    /api/firewall/vips                         - List VIPs (IPv4 only)
POST   /api/firewall/vips                         - Create VIP
```

### Route APIs
```
GET    /api/routes                   - List static routes (filterable by ip_version)
POST   /api/routes                   - Create route
GET    /api/routes/{id}              - Get route details
DELETE /api/routes/{id}              - Delete route
POST   /api/routes/{id}/sync         - Sync to device
GET    /api/routes/device/{device_id} - Routes on specific device
```

### BGP Monitoring APIs (read-only)
```
GET    /api/routing/bgp/sessions                  - All BGP sessions
GET    /api/routing/bgp/sessions/{device_id}      - BGP sessions on a device
GET    /api/routing/bgp/conflicts                 - Prefixes overlapping NMS-managed pools
GET    /api/routing/ospf/neighbors                - OSPF neighbor states
```

### Neighbor APIs (ARP + NDP)
```
GET    /api/neighbors                - List entries (filterable by protocol: arp|ndp)
POST   /api/neighbors                - Create static entry
DELETE /api/neighbors/{id}           - Delete entry
GET    /api/neighbors/device/{device_id} - Entries on specific device
POST   /api/neighbors/sync           - Sync all entries
```

### Infrastructure / Topology APIs
```
# Sites
GET    /api/sites                    - List all sites
POST   /api/sites                    - Create site
GET    /api/sites/{id}               - Site details (with racks, stats)
PUT    /api/sites/{id}               - Update site

# Racks
GET    /api/racks                    - List all racks
POST   /api/racks                    - Create rack
GET    /api/racks/{id}               - Rack details (with installed devices)
PUT    /api/racks/{id}               - Update rack
GET    /api/racks/{id}/diagram       - Visual rack elevation data

# Cables
GET    /api/cables                   - List cables
POST   /api/cables                   - Register cable
GET    /api/cables/{id}              - Cable details
PUT    /api/cables/{id}              - Update cable
DELETE /api/cables/{id}              - Remove cable
GET    /api/cables/device/{device_id} - All cables for a device
GET    /api/cables/trace/{device_id}/{port} - Trace cable path (uses materialized paths)
GET    /api/cables/impact/{cable_id}  - What paths affected if this cable removed?

# Topology
GET    /api/topology                 - Get topology (logical view)
GET    /api/topology/site/{site_id}  - Site-specific topology
GET    /api/topology/physical        - Physical layout (racks + cables)
POST   /api/topology/discover        - Run neighbor discovery
POST   /api/topology/layout/save     - Save node positions
GET    /api/topology/snapshots       - List snapshots
POST   /api/topology/snapshots       - Create snapshot
```

### Monitoring APIs (Zabbix proxy)
```
GET    /api/monitoring/device/{id}/health     - Device health from Zabbix
GET    /api/monitoring/device/{id}/traffic    - Interface traffic from Zabbix
GET    /api/monitoring/device/{id}/alerts     - Active alerts from Zabbix
GET    /api/monitoring/overview               - All device health summary
```

### VPN APIs
```
GET    /api/vpn/gateways             - List gateways
POST   /api/vpn/gateways             - Create gateway
GET    /api/vpn/tunnels              - List tunnels
POST   /api/vpn/tunnels              - Create tunnel
GET    /api/vpn/users                - List VPN users
POST   /api/vpn/users                - Create user
DELETE /api/vpn/users/{id}           - Delete user
```

### Audit APIs
```
GET    /api/audit/logs               - Query audit logs (filterable by action, resource, user, date)
GET    /api/audit/logs/export        - Export logs (CSV/JSON)
GET    /api/audit/changes/{resource} - Changes for resource
```

### NIC APIs
```
GET    /api/nics                           - List all server NICs (filterable by server, vlan, switch)
GET    /api/nics/{id}                      - NIC details (connectivity, IPs, cable)
PUT    /api/nics/{id}                      - Update NIC network config (VLAN, port assignment)
GET    /api/nics/server/{ims_server_id}    - All NICs for a specific server
GET    /api/nics/switch/{device_id}        - All NICs connected to a switch (shows switch port occupancy)
POST   /api/nics/sync/{ims_server_id}      - Force re-sync NIC data from IMS for a server
```

### Settings / System APIs
```
GET    /api/settings/secrets/health  - Vault connectivity status
GET    /api/settings/redis/health    - Redis connectivity status
```

### IMS Integration APIs
```
GET    /api/integration/ims/server/{id}/network     - Get network info for IMS server
GET    /api/integration/ims/server/{id}/connections - Get physical connections for server
```

---

## 14. Frontend Specification

### 14.1 Technology Stack
- **Rendering**: PHP server-rendered templates (no separate SPA build process)
- **CSS Framework**: Tailwind CSS (via CDN or compiled with standalone CLI — no Node.js required for basic use)
- **JS Reactivity**: Alpine.js — lightweight (16KB), Vue-like directives in HTML, handles dropdowns, modals, tab switching, form validation without a build step
- **Topology Visualization**: Cytoscape.js — network graphs; D3.js — charts and custom visuals
- **3D Rack Views**: Three.js — optional, for rack elevation rendering
- **Charts**: Chart.js — device health, IPAM utilization gauges
- **API Calls from frontend**: Fetch API (native) or Axios — calls NMS JSON API from PHP-rendered pages
- **No build tool required**: Tailwind CLI (optional, for purging unused CSS in production); Alpine.js and other libs loaded via CDN script tags or bundled as static assets

**Rationale:** Backend is PHP. Server-rendered templates eliminate a separate frontend build pipeline, reduce deployment complexity, and keep the stack uniform. Alpine.js covers all reactive UI needs (dynamic forms, modals, live data refresh) without JSX or a virtual DOM. D3.js/Cytoscape.js/Three.js work identically in plain HTML — they don't require React/Vue wrappers.

### 14.2 Page Structure

```
/                           - Dashboard
/devices                    - Device list (with cluster badges, drift indicators)
/devices/:id                - Device details (ports, connections, config, drift status)
/devices/:id/ports          - Port management with cable tracking

/clusters                   - HA cluster management
/clusters/:id               - Cluster details (member status, failover history)

/drift                      - Drift management (all open drifts across devices)
/drift/:id                  - Drift details with diff view + resolve actions

/sites                      - Site overview (world map with all DCs)
/sites/:id                  - Site details (racks, devices, connectivity)
/sites/:id/racks            - Rack layout view

/racks/:id                  - Rack elevation view (visual U diagram)
/racks/:id/cables           - Cable management for rack

/ipam                       - IPAM overview (pool utilization across sites)
/ipam/pools                 - Pool management
/ipam/pools/:id             - Pool details with IP grid
/ipam/assignments           - All assignments
/ipam/search                - IP search

/firewall                   - Firewall overview
/firewall/policies          - Policy management (filterable by ip_version)
/firewall/policies/:id      - Policy editor
/firewall/objects           - Address/Service objects

/routes                     - Static route management
/routes/device/:id          - Routes per device

/routing/bgp                - BGP session monitoring dashboard
/routing/bgp/conflicts      - BGP prefix conflicts view

/neighbors                  - ARP + NDP management

/topology                   - Network topology map (logical)
/topology/physical          - Physical topology (racks + cables)
/topology/site/:id          - Per-site topology
/topology/fullscreen        - Fullscreen topology

/vpn                        - VPN management
/vpn/tunnels                - Site-to-site tunnels
/vpn/users                  - Remote access users

/audit                      - Audit logs
/audit/changes              - Configuration changes

/settings                   - System settings
/settings/zabbix            - Zabbix integration settings
/settings/vault             - Secrets manager health
/settings/vendors           - Vendor API configs (credentials via Vault)

// Note: User/role management lives in IMS — no /settings/users or /settings/roles in NMS

/nics                       - NIC overview (all server NICs, filterable by site/switch/VLAN)
/nics/server/:ims_server_id - NICs for a specific server (connectivity + IP assignments)
```

### 14.3 Dashboard Components

**Main Dashboard:**
- System health overview (devices online/offline, cluster status)
- Multi-site map (world/regional view with site status markers)
- IPAM utilization charts (per pool, per address family)
- Active drift alerts (devices with open drifts)
- Recent audit entries
- Quick actions (Add Device, Add Cable, etc.)

**Topology View:**
- Interactive network diagram (logical connections)
- Cluster nodes shown as grouped pair
- Drift status indicators on nodes
- Site-level view: all devices and inter-connections
- Global view: site-to-site links on a map
- Layer toggles (Edge / Core / Access / Servers)
- Cable trace: click a port, see the full materialized path

**Physical View:**
- Rack elevation diagrams (visual rack with U slots)
- Patch panel port mapping (front/rear view)
- Cable tracing through patch panels
- Cross-rack connections visualized
- Empty rack slots highlighted

### 14.4 UI Structure (PHP Templates + Alpine.js)

```
views/
├── layout/
│   ├── _base.php          - HTML shell (head, nav, footer)
│   ├── _sidebar.php       - Navigation sidebar
│   ├── _header.php        - Top bar (breadcrumb, user info from JWT)
│   └── _flash.php         - Toast / flash message partial
├── partials/
│   ├── _table.php         - Reusable data table (Alpine.js sortable/filterable)
│   ├── _modal.php         - Modal wrapper (Alpine.js x-show)
│   ├── _badge.php         - Status/drift badge
│   ├── _confirm.php       - Delete confirmation dialog
│   └── _pagination.php    - Pagination controls
├── devices/
│   ├── index.php          - Device list (with cluster badges, drift indicators)
│   ├── show.php           - Device detail (ports, connections, drift status)
│   └── ports.php          - Port management
├── clusters/
│   ├── index.php
│   └── show.php
├── drift/
│   ├── index.php          - All open drifts
│   └── show.php           - Diff view + resolve actions (Alpine.js diff toggle)
├── sites/
│   ├── index.php          - World map (Leaflet.js or inline SVG)
│   └── show.php
├── racks/
│   ├── show.php           - Rack elevation (Three.js or pure CSS/SVG)
│   └── cables.php
├── ipam/
│   ├── index.php          - Pool utilization overview
│   ├── pools/index.php
│   ├── pools/show.php     - IP grid (Alpine.js rendered)
│   └── assignments.php
├── firewall/
│   ├── policies/index.php
│   ├── policies/edit.php  - Rule builder (Alpine.js dynamic rows)
│   └── objects/index.php
├── routes/
│   └── index.php
├── routing/
│   ├── bgp.php
│   └── conflicts.php
├── neighbors/
│   └── index.php
├── topology/
│   ├── logical.php        - Cytoscape.js canvas
│   ├── physical.php       - Physical racks + cable traces
│   └── site.php
├── nics/
│   ├── index.php          - NIC overview (filterable by VLAN/switch/site)
│   └── server.php         - NICs for a specific server
├── vpn/
│   ├── tunnels.php
│   └── users.php
├── audit/
│   └── index.php
└── settings/
    ├── index.php
    ├── zabbix.php
    ├── vault.php
    └── vendors.php        - Vendor API credential health

js/
├── topology.js            - Cytoscape.js init + layout logic
├── rack-view.js           - Three.js rack elevation
├── charts.js              - Chart.js initialization
├── ipam-grid.js           - IP grid rendering
└── app.js                 - Global Alpine.js store, fetch helpers, JWT refresh
```

---

## 15. Project Structure

```
nms/
├── api/
│   ├── api.php                      # Main router
│   ├── handlers/
│   │   ├── auth/
│   │   ├── devices/
│   │   ├── clusters/                # HA cluster endpoints
│   │   ├── drift/                   # Drift management endpoints
│   │   ├── ipam/
│   │   │   ├── pools/
│   │   │   ├── assignments/
│   │   │   └── subnets/
│   │   ├── firewall/
│   │   │   ├── policies/
│   │   │   ├── objects/
│   │   │   └── vips/
│   │   ├── routes/
│   │   ├── routing/                 # BGP/OSPF monitoring
│   │   ├── neighbors/               # ARP + NDP
│   │   ├── infrastructure/
│   │   │   ├── sites/
│   │   │   ├── racks/
│   │   │   └── cables/
│   │   ├── topology/
│   │   ├── monitoring/              # Zabbix proxy endpoints
│   │   ├── vpn/
│   │   ├── audit/
│   │   ├── settings/
│   │   ├── nics/                    # NIC management endpoints
│   │   └── integration/
│   │       └── ims/
│   └── middleware/
│       ├── AuthMiddleware.php
│       ├── RBACMiddleware.php        # Reads permissions[] JWT claim — no DB query
│       ├── RateLimitMiddleware.php
│       ├── IdempotencyMiddleware.php  # X-Idempotency-Key handling
│       └── AuditMiddleware.php
│
├── core/
│   ├── config/
│   │   ├── app.php
│   │   ├── database.php             # MongoDB connection
│   │   ├── redis.php                # Redis connection (required)
│   │   ├── vault.php                # Vault / secrets manager config
│   │   ├── vendors.php              # Vendor API configs
│   │   └── zabbix.php
│   ├── database/
│   │   ├── MongoDB.php
│   │   ├── Collection.php
│   │   └── Migration.php
│   ├── auth/
│   │   ├── JWTHelper.php            # Token generation/validation
│   │   ├── TokenBlocklist.php       # Redis-backed revocation
│   │   ├── M2MTokenHelper.php       # Machine-to-machine tokens
│   │   └── ImsTicketClient.php      # Create/update tickets in IMS ticket system via M2M
│   ├── helpers/
│   │   ├── Response.php
│   │   ├── Validator.php
│   │   ├── IPUtils.php              # IPv4 + IPv6 utilities
│   │   ├── RetryHandler.php         # Exponential backoff with jitter
│   │   ├── CircuitBreaker.php       # Per-device circuit breaker
│   │   └── Logger.php
│   └── models/
│       ├── devices/
│       │   ├── DeviceManager.php
│       │   ├── ClusterManager.php    # HA cluster operations
│       │   ├── DeviceInterface.php
│       │   └── DeviceFactory.php
│       ├── ipam/
│       │   ├── PoolManager.php
│       │   ├── IPAllocator.php       # Atomic findOneAndUpdate allocation
│       │   ├── SubnetCalculator.php
│       │   └── ConflictChecker.php   # Includes BGP conflict check
│       ├── firewall/
│       │   ├── PolicyManager.php
│       │   ├── PolicyBuilder.php     # IPv4 vs IPv6 policy differences
│       │   └── ObjectManager.php
│       ├── routing/
│       │   ├── RouteManager.php
│       │   ├── RouteSync.php
│       │   └── BGPMonitor.php        # Read-only BGP/OSPF polling
│       ├── neighbors/
│       │   ├── NeighborManager.php   # ARP + NDP
│       │   └── NeighborSync.php
│       ├── nics/
│       │   └── NicManager.php        # server_nics: sync from IMS webhook, port/VLAN/IP linking
│       ├── infrastructure/
│       │   ├── SiteManager.php
│       │   ├── RackManager.php
│       │   └── CableManager.php
│       ├── topology/
│       │   ├── TopologyBuilder.php
│       │   ├── PathMaterializer.php  # Pre-computed connectivity paths
│       │   ├── NodeDiscovery.php
│       │   └── LinkDiscovery.php
│       ├── monitoring/
│       │   └── ZabbixClient.php
│       ├── drift/
│       │   └── DriftDetector.php     # Section-by-section config comparison
│       └── secrets/
│           ├── SecretsManagerInterface.php
│           ├── VaultSecretsManager.php     # HashiCorp Vault
│           └── AppEncryptedSecretsManager.php  # Fallback (temporary)
│
├── vendors/
│   ├── VendorAdapter.php            # Abstract base (with retry + circuit breaker)
│   ├── mikrotik/
│   │   ├── MikroTikAdapter.php
│   │   ├── MikroTikAPI.php
│   │   └── MikroTikParser.php
│   ├── fortigate/
│   │   ├── FortiGateAdapter.php
│   │   ├── FortiGateAPI.php
│   │   └── FortiGateParser.php
│   ├── vyos/
│   │   ├── VyOSAdapter.php
│   │   └── VyOSAPI.php
│   ├── cisco/
│   │   ├── CiscoAdapter.php
│   │   └── CiscoRESTCONF.php
│   └── aruba/
│       ├── ArubaAdapter.php
│       └── ArubaAPI.php
│
├── services/
│   └── scheduler/
│       ├── DevicePoller.php         # Periodic device status checks
│       ├── DriftScanner.php         # Periodic drift detection
│       ├── BGPPoller.php            # Periodic BGP session polling
│       ├── TopologyRefresh.php
│       └── BackupScheduler.php
│
├── database/
│   ├── setup.php                    # Collection + index creation
│   └── seeds/
│       └── default_services.php     # Default firewall service objects
│       // Note: roles.php + permissions.php removed — owned by IMS
│
├── views/                               # PHP templates (server-rendered)
│   ├── layout/
│   │   ├── _base.php
│   │   ├── _sidebar.php
│   │   └── _header.php
│   ├── partials/
│   ├── devices/
│   ├── clusters/
│   ├── drift/
│   ├── sites/
│   ├── racks/
│   ├── ipam/
│   ├── firewall/
│   ├── routes/
│   ├── routing/
│   ├── neighbors/
│   ├── topology/
│   ├── nics/
│   ├── vpn/
│   ├── audit/
│   └── settings/
│
├── public/
│   ├── index.php                        # Web entry point
│   ├── css/
│   │   └── app.css                      # Compiled Tailwind CSS
│   └── js/
│       ├── app.js                       # Alpine.js store + fetch helpers
│       ├── topology.js                  # Cytoscape.js init
│       ├── rack-view.js                 # Three.js rack elevation
│       ├── charts.js                    # Chart.js
│       └── ipam-grid.js                 # IP grid rendering
│
├── tests/                           # Tests live alongside code, run per-phase
│   ├── unit/
│   ├── integration/
│   └── api/
│
├── .env.example                     # NO secrets — references Vault paths only
├── composer.json
└── README.md
```

---

## 16. Implementation Phases

> Rewritten with: explicit dependencies, exit criteria per phase, testing embedded in every phase (no standalone "testing" phase), risk gates at critical integration points.

### Phase 1: Foundation (Core Infrastructure)

**Dependencies:** None (first phase)

**Work:**
- Project setup: Composer, directory structure, autoloading
- MongoDB connection wrapper + base collection class
- Redis connection wrapper (required — not optional)
- Secrets manager abstraction: `SecretsManagerInterface` + `VaultSecretsManager` + `AppEncryptedSecretsManager` (fallback)
- JWT authentication: generation, validation, refresh rotation, Redis-backed revocation blocklist
- M2M token support (for IMS integration)
- RBAC middleware: `RBACMiddleware.php` reads `permissions[]` claim from JWT — **no roles/permissions DB in NMS** (owned by IMS)
- Standardized API response format
- Audit logging framework (middleware-based)
- Input validation helpers
- `IPUtils.php`: IPv4 + IPv6 address utilities (parsing, CIDR math, version detection)
- Error handling + standardized error responses
- Rate limiting middleware (Redis-backed)
- Idempotency middleware (Redis-backed, `X-Idempotency-Key` header handling)
- `RetryHandler.php`: exponential backoff with jitter
- `CircuitBreaker.php`: per-device failure tracking

**Testing:**
- Unit tests: JWT generation/validation, IPv4/IPv6 utilities, RBAC JWT claim checks
- Unit tests: retry handler backoff calculation, circuit breaker state transitions
- Integration test: MongoDB connection + basic CRUD
- Integration test: Redis connection + blocklist operations
- Integration test: Vault connection (or fallback encryption round-trip)

**Exit Criteria:**
- [ ] API returns standardized responses with proper auth headers
- [ ] JWT login/logout/refresh cycle works end-to-end
- [ ] Token revocation via Redis blocklist blocks previously valid tokens
- [ ] M2M token issuance and validation works
- [ ] Rate limiting rejects excess requests
- [ ] Idempotency key returns cached response on duplicate request
- [ ] SecretsManager can store and retrieve a test credential
- [ ] All Phase 1 unit + integration tests pass

---

### Phase 2: Physical Infrastructure Model

**Dependencies:** Phase 1

**Work:**
- Sites CRUD (data centers, locations, geospatial coordinates)
- Racks CRUD (with U-slot management, installed_devices tracking)
- Patch panels as first-class devices (role: `patch_panel`, front/rear port mapping)
- Cable management (registration, tracking, endpoint validation)
- Port-to-port connection tracking (including through patch panels)
- `connectivity_paths` collection + path materialization engine
- Path invalidation on cable change
- Rack elevation data model
- Infrastructure API endpoints (sites, racks, cables, cable trace)

**Testing:**
- Unit tests: cable validation (both endpoints exist, ports not already connected)
- Unit tests: path materialization (given cables A→PP→B, compute full path)
- Unit tests: path invalidation (modify cable, verify paths marked invalid)
- Integration test: create site → rack → devices → cables → trace path end-to-end

**Exit Criteria:**
- [ ] Sites, racks, and devices can be created with full location hierarchy
- [ ] Patch panels model front/rear port pairs correctly
- [ ] Cable trace from server NIC through patch panel to switch port returns correct materialized path
- [ ] Modifying a cable invalidates and recomputes affected paths
- [ ] `GET /api/cables/trace/{device}/{port}` returns hop-by-hop path
- [ ] `GET /api/cables/impact/{cable_id}` returns affected paths
- [ ] All Phase 2 tests pass

---

### Phase 3: Device Management + Vendor Adapters

**Dependencies:** Phase 1, Phase 2

**Work:**
- Device CRUD operations (with `cluster_id`, `drift`, `circuit_breaker` fields)
- Device credentials: vault-reference-only storage pattern
- Port inventory management
- Device connectivity testing
- Configuration backup system
- `device_clusters` collection + cluster CRUD
- `cluster_events` collection
- MikroTik adapter implementation (all interface methods including `getConfigSections`, `getBGPSessions`)
- FortiGate adapter implementation (including HA status, IPv6 policies)
- VyOS adapter implementation
- Device abstraction layer (`DeviceFactory`)
- All adapters wrapped with `RetryHandler` + `CircuitBreaker`
- Safe command execution with vendor-specific allowlists

**Testing:**
- Unit tests: device factory returns correct adapter for vendor
- Unit tests: command allowlist rejects disallowed commands
- Integration tests: connect to real MikroTik (or mock) → get interfaces, routes, ARP
- Integration tests: connect to real FortiGate (or mock) → get policies, HA status
- Integration tests: retry handler retries on timeout, stops on 4xx
- Integration tests: circuit breaker opens after 5 failures, half-opens after cooldown

**> RISK GATE: First real device connection.** Before proceeding to Phase 4, at least one vendor adapter must successfully connect to a real device (MikroTik or FortiGate) and retrieve live data (interfaces, routes, or firewall rules). This validates the entire adapter architecture.

**Exit Criteria:**
- [ ] At least one vendor adapter connects to a real device and retrieves live data
- [ ] Credentials are stored in Vault (or fallback encryption) — NOT plaintext in MongoDB
- [ ] HA cluster can be created with two member devices
- [ ] Cluster status endpoint polls all member nodes individually
- [ ] Circuit breaker opens after consecutive failures and auto-recovers
- [ ] `POST /api/devices/{id}/execute` only accepts allowlisted commands
- [ ] All Phase 3 tests pass

---

### Phase 4: IPAM Core

**Dependencies:** Phase 1, Phase 3 (needs device/cluster references for pools)

**Work:**
- IP block management (IPv4 + IPv6)
- IP pool management (with `ip_version` required on all records)
- Subnet management
- IP assignment/release with **atomic `findOneAndUpdate`** (race-condition safe)
- Automatic next-IP allocation (atomic)
- Conflict detection (within pools + cross-pool)
- Assignment history
- L2 vs L3 IP handling (different workflows)
- IPv6 pool handling (/64 allocation units, no broadcast, link-local gateways)
- IP reservation management

**Testing:**
- Unit tests: IPv4 + IPv6 CIDR calculations (usable range, next available)
- Unit tests: L2 vs L3 assignment difference validation
- **Concurrency test: fire 10 parallel "assign next available" requests at same pool → verify no two get the same IP** (validates atomic allocation)
- Integration test: full lifecycle — create block → pool → assign L2 → assign L3 → release → verify counters

**Exit Criteria:**
- [ ] IPv4 and IPv6 pools can be created and managed
- [ ] Atomic IP allocation works under concurrent requests (no duplicates)
- [ ] L2 assignment: IP assigned, no route created
- [ ] L3 assignment: IP assigned + route reference + neighbor entry reference populated
- [ ] IPv6 pools track /64 allocations, not per-IP counts
- [ ] Pool utilization counters update correctly on assign/release
- [ ] All Phase 4 tests pass

---

### Phase 5: Network Configuration

**Dependencies:** Phase 3 (adapters), Phase 4 (IPAM)

**Work:**
- Static route management (with `cluster_id`, `ip_version`)
- Route sync to devices via vendor adapters
- Neighbor entry management (ARP for IPv4, NDP for IPv6)
- Neighbor sync to devices
- Firewall policy management (with `ip_version`, `cluster_id`)
- Firewall object management (addresses, groups, services)
- VIP/NAT management (IPv4 only — firewall_vips collection)
- Policy sync to devices
- IPv4 workflow: VIP + DNAT + policy
- IPv6 workflow: direct address policy (NO VIP, NO NAT)
- BGP session monitoring (read-only polling from devices)
- OSPF neighbor monitoring (read-only)
- BGP conflict checker for static route creation

**Testing:**
- Unit tests: IPv4 policy builder creates VIP + NAT policy
- Unit tests: IPv6 policy builder creates direct address policy WITHOUT VIP/NAT
- Integration test: create route → sync to device → verify on device → delete → verify removed
- Integration test: create firewall policy → sync → verify → cleanup
- Integration test: BGP session polling returns valid data from device

**Exit Criteria:**
- [ ] Routes can be created, synced to device, and verified (IPv4 + IPv6)
- [ ] ARP entries sync to devices (IPv4)
- [ ] NDP entries sync to devices (IPv6)
- [ ] IPv4 firewall policies create VIP + DNAT correctly
- [ ] IPv6 firewall policies use direct address matching (no VIP, no NAT)
- [ ] BGP sessions are polled and stored (monitoring only, no writes to devices)
- [ ] BGP conflict checker correctly identifies prefix overlaps
- [ ] All config pushes go to cluster management IP when device is in a cluster
- [ ] All Phase 5 tests pass

---

### Phase 6: IMS Integration & NIC Management

**Dependencies:** Phase 4 (IPAM), Phase 5 (Network Config)

**Work:**
- IMS integration endpoints (server network info, physical connections)
- Bidirectional webhook event delivery (NMS→IMS, IMS→NMS)
- `server_nics` collection setup + `NicManager.php`
- NIC sync on `server.nic_change` webhook (update MAC, port, VLAN, IP links)
- NIC API endpoints (`/api/nics/*`)
- Full `ImsTicketClient.php` implementation (drift tickets, device unreachable tickets)
- `ip.changed` and `drift.detected` webhook events to IMS

**Testing:**
- Integration test: IMS webhook event delivery (mock IMS endpoint)
- Integration test: `server.nic_change` webhook updates `server_nics` correctly

**Exit Criteria:**
- [ ] NIC sync on `server.nic_change` webhook updates server_nics correctly
- [ ] `GET /api/integration/ims/server/{id}/network` returns correct IP/firewall info
- [ ] `GET /api/integration/ims/server/{id}/connections` returns physical path
- [ ] IMS ticket creation works for `nms_drift` and `nms_device_unreachable` types
- [ ] All Phase 6 tests pass

---

### Phase 7: Topology & Discovery

**Dependencies:** Phase 2 (physical infra), Phase 3 (device adapters)

**Work:**
- Neighbor discovery (CDP, LLDP, MikroTik Neighbor)
- Auto-detect connections from discovery data
- Match discovered links to cable records
- Topology data aggregation (nodes, links, layers)
- Topology API (logical + physical views)
- Topology snapshots
- Path recomputation integration with cable changes

**Testing:**
- Integration test: run discovery → verify new connections detected
- Integration test: topology API returns correct nodes + links for a site

**Exit Criteria:**
- [ ] Discovery finds at least one new neighbor connection from a real device
- [ ] Topology API returns valid graph data for visualization
- [ ] Topology snapshots can be created and compared
- [ ] All Phase 7 tests pass

---

### Phase 8: Monitoring + Drift Detection

**Dependencies:** Phase 3 (device adapters), Phase 7 (topology)

**Work:**
- Zabbix API client
- Device-to-Zabbix host mapping
- Health/traffic data proxy endpoints
- Alert aggregation
- Redis caching for Zabbix data (60s TTL)
- Drift detection scanner (periodic, section-by-section comparison)
- Drift resolution workflow (push/pull/ignore)
- Drift API endpoints

**Testing:**
- Integration test: Zabbix client retrieves device health data
- Integration test: Redis cache returns cached data within TTL, fetches fresh after TTL
- **Critical test: manually add a firewall rule on device → drift scanner detects it → resolve as "pull" → NMS database updated**

**Exit Criteria:**
- [ ] Zabbix health data displays for managed devices
- [ ] Redis cache works with proper TTL expiration
- [ ] Drift scanner detects manually added firewall rule on a real device
- [ ] Drift resolution (push/pull/ignore) works correctly
- [ ] All Phase 8 tests pass

---

### Phase 9: VPN & Additional Vendors

**Dependencies:** Phase 3, Phase 5

**Work:**
- VPN gateway management (secrets in Vault)
- Site-to-site tunnel management
- VPN user management
- Cisco IOS-XE adapter implementation
- Aruba CX adapter implementation

**Testing:**
- Integration tests: VPN tunnel creation + status check
- Integration tests: Cisco/Aruba adapters connect and retrieve data (if hardware available)

**Exit Criteria:**
- [ ] VPN PSKs stored in Vault, not MongoDB
- [ ] VPN tunnel status is checkable
- [ ] At least one additional vendor adapter works (Cisco or Aruba)
- [ ] All Phase 9 tests pass

---

### Phase 10: Frontend Development

**Dependencies:** All backend phases (1-9) complete

**Stack:** PHP server-rendered templates + Tailwind CSS + Alpine.js + Cytoscape.js + Three.js + Chart.js

**Work:**
- PHP template base layout (`_base.php`, `_sidebar.php`, `_header.php`) + Alpine.js global store
- Tailwind CSS setup (standalone CLI for production build, CDN for dev)
- Authentication UI (login page, JWT refresh interceptor in `app.js`)
- Dashboard (health overview, IPAM utilization charts via Chart.js, drift alerts, recent jobs)
- Site/Rack management UI (site map, rack elevation via Three.js or SVG)
- Cable management UI (with cable trace visualization through patch panels)
- Device management UI (with cluster badges, drift indicators, port views)
- Cluster management UI
- Drift management UI (diff view, resolve actions — Alpine.js toggles)
- IPAM UI with IP grid (IPv4 + IPv6 pools — Alpine.js rendered grid)
- Firewall policy UI (dynamic rule builder via Alpine.js)
- Route management UI
- BGP monitoring dashboard
- Topology visualization (Cytoscape.js — logical + physical, with cluster grouping)
- **NIC management UI** (NIC overview, per-server NIC + connectivity + IP view)
- Monitoring dashboard (Zabbix health data)
- Audit log viewer
- Settings pages (Zabbix config, Vault health, vendor configs) — **no user/role pages (IMS owns those)**

**Testing:**
- E2E tests: login → navigate → create device → view in topology
- E2E tests: drift detected → view diff → resolve in UI
- E2E test: server NIC change webhook → NIC record updates in UI

**Exit Criteria:**
- [ ] All pages render correctly and interact with backend APIs
- [ ] Topology visualization shows devices, links, clusters, and drift status
- [ ] Rack elevation view shows device placement with patch panels
- [ ] Cable trace shows full path through patch panels
- [ ] NIC page shows server → cable → switch port connectivity correctly
- [ ] All E2E tests pass

---

## Summary

This NMS provides a comprehensive infrastructure architecture and configuration platform:

1. **Physical Infrastructure Mapping** — Complete 3D model of sites, racks, devices, ports, cables, and patch panels. Know exactly what is plugged where, across all data centers.

2. **Unified Multi-Vendor Management** — Single interface for MikroTik, FortiGate, VyOS, Cisco, and Aruba devices, with retry/backoff and circuit breaker resilience.

3. **HA Cluster Awareness** — Firewall HA pairs and router VRRP groups modeled as first-class entities. Config pushes go to the cluster, not individual nodes.

4. **Complete IPAM** — Dual-stack (IPv4 + IPv6) pool management with atomic race-free allocation, conflict detection, and BGP prefix overlap warnings.

5. **Firewall Automation** — IPv4 policies with VIP/NAT, IPv6 policies with direct address matching. Centralized management with device synchronization.

6. **Configuration Drift Detection** — Periodic section-by-section comparison against live device state, with push/pull/ignore resolution workflow.

7. **Infrastructure Visualization** — Interactive topology mapping with physical rack views, cable tracing through patch panels, and pre-computed path materialization.

8. **IMS Synergy** — Bidirectional webhook integration. Combined with IMS, provides the complete hardware-to-network picture: which server is in which rack, connected to which port through which patch panel, with which IPs and firewall rules. Shared RBAC (IMS-owned) and shared ticketing (IMS-owned) eliminate duplicated infrastructure across the two systems.

9. **NIC Tracking** — `server_nics` collection maps each server NIC to its switch port, VLAN, cable, and IP assignments. The missing link between IMS (physical NIC hardware) and NMS (network config): cable path from NIC → patch panel → switch port.

10. **Production-Ready Auth** — JWT with key rotation, Redis-backed revocation, M2M tokens for IMS integration. RBAC is **shared with IMS**: permissions embedded in JWT, no separate auth DB in NMS.

11. **Secrets Management** — Device credentials never stored in MongoDB. Vault references only, with provider abstraction layer.

12. **Zabbix Monitoring** — Delegated monitoring with Redis-cached API proxy. NMS focuses on architecture and configuration, not monitoring.
