# NMS Implementation Roadmap

> **Ground rule:** Each phase is designed to be completed in a single context window session (~100K tokens max).
> Start a **fresh context window** for each new phase.
> When starting a phase, reference: `@NMS-PLAN.md` + `@IMPLEMENTATION_ROADMAP.md`.

---

## How to Use This Roadmap

1. Open a new Claude context window for each phase
2. Reference `@NMS-PLAN.md` (the authoritative spec) and `@IMPLEMENTATION_ROADMAP.md` (this file)
3. Say: *"Let's implement Phase X. Follow the IMPLEMENTATION_ROADMAP and NMS-PLAN strictly."*
4. Complete all files, tests, and exit criteria listed for that phase
5. Use `/gsd:pause-work` if the context fills up mid-phase
6. Do **not** implement anything outside the phase's listed scope

---

## Phase Dependencies

```
Phase 1 (Foundation)
    └── Phase 2 (Physical Infrastructure)
    └── Phase 3 (Device Management)
            └── Phase 4 (IPAM)
            │       └── Phase 5 (Network Config)
            │               └── Phase 6 (Provisioning & IMS Integration)
            └── Phase 7 (Topology & Discovery) ← also needs Phase 2
            └── Phase 8 (Monitoring + Drift) ← also needs Phase 7
            └── Phase 9 (VPN & More Vendors)
Phase 10 (Frontend) ← all backend phases complete
```

---

## Phase 1: Foundation (Core Infrastructure)

**Depends on:** Nothing (first phase)
**Estimated size:** ~20K tokens

### What to build

**Directory structure & Composer:**
- `composer.json` — require: `mongodb/mongodb`, `firebase/php-jwt`, `predis/predis`
- Full directory scaffold from NMS-PLAN Section 16 (all empty dirs + `.gitkeep` files)
- `autoload` configured for `src/` PSR-4 namespaces

**Config files (`core/config/`):**
- `app.php` — environment loader (reads `.env`)
- `database.php` — MongoDB connection config
- `redis.php` — Redis connection config (required, not optional)
- `vault.php` — Vault / secrets manager config
- `.env.example` — NO secrets, only Vault path references

**Database layer (`core/database/`):**
- `MongoDB.php` — singleton connection wrapper
- `Collection.php` — base collection class with standard CRUD helpers
- `Migration.php` — runs `database/setup.php` index creation
- `database/setup.php` — creates ALL indexes listed in NMS-PLAN Section 13

**Secrets manager (`core/models/secrets/`):**
- `SecretsManagerInterface.php` — `get(string $path): string`, `put(string $path, string $value): void`
- `VaultSecretsManager.php` — HashiCorp Vault HTTP API implementation
- `AppEncryptedSecretsManager.php` — AES-256-GCM fallback (key from OS keyring env var)

**Auth system (`core/auth/`):**
- `JWTHelper.php` — `generate(array $claims): string`, `validate(string $token): object`, dual-key rotation support
- `TokenBlocklist.php` — Redis blocklist: `revoke(string $jti, int $ttl)`, `isRevoked(string $jti): bool`, bulk revocation via `user:{id}:tokens_valid_after`
- `M2MTokenHelper.php` — issue/validate M2M tokens with `aud` claim checking
- `ImsTicketClient.php` — stub only (implements interface, full logic in Phase 6)

**Helpers (`core/helpers/`):**
- `Response.php` — `json(array $data, int $status)`, `error(string $msg, int $status)`, `paginated(array $data, int $total, int $page)`
- `Validator.php` — field validation (required, type, regex, IP, CIDR)
- `IPUtils.php` — `parseCIDR()`, `isIPv4()`, `isIPv6()`, `detectVersion()`, `getUsableRange()`, `networkContains()`, `nextAvailable()`
- `RetryHandler.php` — `withRetry(callable $fn, int $maxRetries = 3): mixed`, exponential backoff with jitter (base 2s, max 30s), retries on timeout/5xx/429, NOT on 4xx
- `CircuitBreaker.php` — Redis-backed per-device state: `call(string $deviceId, callable $fn): mixed`, opens after 5 failures, 60s cooldown, half-open probe
- `Logger.php` — PSR-3 compatible structured logger

**API layer (`api/`):**
- `api.php` — main router (parses method + path, routes to handler, applies middleware stack)
- `middleware/AuthMiddleware.php` — validates JWT, checks blocklist, attaches claims to request
- `middleware/RBACMiddleware.php` — reads `permissions[]` from JWT claim, NO MongoDB query
- `middleware/RateLimitMiddleware.php` — Redis sliding window: 100 req/min per user
- `middleware/IdempotencyMiddleware.php` — `X-Idempotency-Key` header: cache response in Redis 24h TTL
- `middleware/AuditMiddleware.php` — logs every mutating request to `audit_logs` collection

**Auth endpoints (`api/handlers/auth/`):**
- `LoginHandler.php` — `POST /api/auth/login` — validates credentials via IMS JWT (or shared key), returns access + refresh tokens
- `RefreshHandler.php` — `POST /api/auth/refresh` — one-time refresh token rotation
- `LogoutHandler.php` — `POST /api/auth/logout` — adds jti to Redis blocklist
- `MeHandler.php` — `GET /api/auth/me` — returns decoded JWT claims
- `M2MTokenHandler.php` — `POST /api/auth/m2m/token` — issues M2M token

**Tests (`tests/`):**
- `unit/JWTHelperTest.php` — generate, validate, expired token rejection, dual-key rotation
- `unit/TokenBlocklistTest.php` — revoke, isRevoked, bulk revocation
- `unit/IPUtilsTest.php` — IPv4 + IPv6 parsing, CIDR math, version detection
- `unit/RetryHandlerTest.php` — backoff calculation, stops on 4xx, retries on 5xx
- `unit/CircuitBreakerTest.php` — state transitions: closed→open→half_open→closed
- `unit/RBACMiddlewareTest.php` — grants access with permission, denies without
- `integration/MongoDBTest.php` — connection + basic CRUD
- `integration/RedisTest.php` — connection + blocklist operations
- `integration/VaultTest.php` — store + retrieve credential (or fallback round-trip)

### Exit criteria (all must pass before Phase 2)
- [ ] `POST /api/auth/login` returns access + refresh tokens
- [ ] `POST /api/auth/logout` blocks previously valid token
- [ ] `POST /api/auth/refresh` rotates token (old refresh rejected after use)
- [ ] M2M token issuance and validation works with correct `aud` claim
- [ ] Rate limiting returns 429 after 100 req/min
- [ ] Idempotency key returns identical response on duplicate POST
- [ ] SecretsManager stores + retrieves a test credential (Vault or fallback)
- [ ] All unit + integration tests pass

---

## Phase 2: Physical Infrastructure Model

**Depends on:** Phase 1
**Estimated size:** ~18K tokens

### What to build

**Models (`core/models/infrastructure/`):**
- `SiteManager.php` — CRUD for `sites` collection, geospatial coordinate handling, stats aggregation
- `RackManager.php` — CRUD for `racks` collection, U-slot management, `installed_devices` array sync, utilization calc
- `CableManager.php` — CRUD for `cables` collection, endpoint validation (both devices exist, ports not already connected), triggers path invalidation on change

**Topology support (`core/models/topology/`):**
- `PathMaterializer.php` — `materialize(deviceId, portName): array`, walks graph from endpoint (max depth 6), stores in `connectivity_paths`, uses `$graphLookup` fallback if pre-computed path is invalid
- Note: `TopologyBuilder.php`, `NodeDiscovery.php`, `LinkDiscovery.php` are stubs only (full impl in Phase 7)

**Devices partial (`core/models/devices/`):**
- `DeviceInterface.php` — full `NetworkDeviceInterface` interface (all methods from NMS-PLAN Section 1.2)
- `DeviceManager.php` — CRUD for `devices` collection only (no vendor connections yet), includes patch panel device type
- `DeviceFactory.php` — stub only (returns `null` for all vendors — full impl in Phase 3)

**API handlers (`api/handlers/`):**
- `infrastructure/sites/ListHandler.php` — `GET /api/sites`
- `infrastructure/sites/CreateHandler.php` — `POST /api/sites`
- `infrastructure/sites/ShowHandler.php` — `GET /api/sites/{id}`
- `infrastructure/sites/UpdateHandler.php` — `PUT /api/sites/{id}`
- `infrastructure/racks/ListHandler.php` — `GET /api/racks`
- `infrastructure/racks/CreateHandler.php` — `POST /api/racks`
- `infrastructure/racks/ShowHandler.php` — `GET /api/racks/{id}` (includes installed_devices)
- `infrastructure/racks/UpdateHandler.php` — `PUT /api/racks/{id}`
- `infrastructure/racks/DiagramHandler.php` — `GET /api/racks/{id}/diagram`
- `infrastructure/cables/ListHandler.php` — `GET /api/cables`
- `infrastructure/cables/CreateHandler.php` — `POST /api/cables`
- `infrastructure/cables/ShowHandler.php` — `GET /api/cables/{id}`
- `infrastructure/cables/UpdateHandler.php` — `PUT /api/cables/{id}`
- `infrastructure/cables/DeleteHandler.php` — `DELETE /api/cables/{id}`
- `infrastructure/cables/DeviceCablesHandler.php` — `GET /api/cables/device/{device_id}`
- `infrastructure/cables/TraceHandler.php` — `GET /api/cables/trace/{device_id}/{port}`
- `infrastructure/cables/ImpactHandler.php` — `GET /api/cables/impact/{cable_id}`
- `devices/ListHandler.php` — `GET /api/devices`
- `devices/CreateHandler.php` — `POST /api/devices`
- `devices/ShowHandler.php` — `GET /api/devices/{id}`
- `devices/UpdateHandler.php` — `PUT /api/devices/{id}`
- `devices/DeleteHandler.php` — `DELETE /api/devices/{id}`
- `devices/PortsHandler.php` — `GET /api/devices/{id}/ports`

**Database:**
- Ensure `database/setup.php` creates all indexes for: `sites`, `racks`, `cables`, `connectivity_paths`, `devices`

**Tests:**
- `unit/CableManagerTest.php` — validates endpoints exist, rejects already-connected port
- `unit/PathMaterializerTest.php` — given cables A→PP→B (patch panel path), returns correct 3-hop path
- `unit/PathInvalidationTest.php` — modify cable → paths using it marked `valid: false`
- `integration/InfrastructureTest.php` — create site → rack → device → cable → trace path end-to-end
- `integration/ImpactTest.php` — create two paths sharing a cable → `GET /api/cables/impact/{id}` returns both

### Exit criteria
- [ ] Sites, racks, devices can be CRUD'd with full location hierarchy
- [ ] Patch panel device created with `role: "patch_panel"`, front/rear port pairs
- [ ] Cable connecting server NIC → patch panel front port is validated correctly
- [ ] `GET /api/cables/trace/{device}/{port}` returns hop-by-hop path through patch panel
- [ ] `GET /api/cables/impact/{cable_id}` returns all connectivity_paths using that cable
- [ ] Modifying a cable sets affected `connectivity_paths.valid = false`
- [ ] All Phase 2 tests pass

---

## Phase 3: Device Management + Vendor Adapters

**Depends on:** Phase 1, Phase 2
**Estimated size:** ~28K tokens

### What to build

**Vendor adapter base (`vendors/`):**
- `VendorAdapter.php` — abstract base class implementing `NetworkDeviceInterface`, wraps every adapter call with `RetryHandler` + `CircuitBreaker`, updates `device.circuit_breaker` state in MongoDB on state change

**MikroTik adapter (`vendors/mikrotik/`):**
- `MikroTikAPI.php` — raw HTTP client for MikroTik REST API (all endpoints from NMS-PLAN Section 1.1)
- `MikroTikParser.php` — normalizes raw API responses into typed arrays (routes, firewall, arp, interfaces)
- `MikroTikAdapter.php` — implements all `NetworkDeviceInterface` methods:
  - `getIpAddresses()`, `addIpAddress()`, `removeIpAddress()`
  - `getRoutes()`, `addStaticRoute()`, `removeRoute()`
  - `getNeighborTable()`, `addStaticNeighbor()`, `removeNeighbor()`
  - `getFirewallRules()`, `addFirewallRule()`, `removeFirewallRule()`
  - `getInterfaces()`, `getInterfaceStatus()`
  - `getSystemInfo()`, `getNeighborDiscovery()`, `backupConfig()`, `restoreConfig()`
  - `getConfigSections()` — returns `['routes' => [...], 'firewall' => [...], 'arp' => [...], 'interfaces' => [...]]`
  - `getBGPSessions()`, `getBGPPrefixesForRange(string $cidr)`
  - `getOSPFNeighbors()`, `getHAStatus()`
  - `executeCommand(string $command)`, `getAllowedCommands()` — allowlist: `ping, traceroute, /tool/torch, /system/resource/print, /interface/print`

**FortiGate adapter (`vendors/fortigate/`):**
- `FortiGateAPI.php` — raw HTTP client (Bearer token auth, all endpoints from NMS-PLAN Section 1.1)
- `FortiGateParser.php` — normalizes responses into typed arrays
- `FortiGateAdapter.php` — implements all `NetworkDeviceInterface` methods including:
  - `getHAStatus()` — polls `/monitor/system/ha-peer`
  - `getBGPSessions()` — polls `/monitor/router/bgp/neighbors`
  - `getBGPPrefixesForRange()` — targeted query against device
  - `getAllowedCommands()` — allowlist: `get system status, get router info routing-table all, diagnose sys top, execute ping, execute traceroute, get system performance status`
  - IPv6 policy support (`getFirewallRules()` includes policy6)

**VyOS adapter (`vendors/vyos/`):**
- `VyOSAPI.php` — raw HTTP client (API key in POST data, `/configure`, `/retrieve`, `/show`)
- `VyOSAdapter.php` — implements `NetworkDeviceInterface` using VyOS config paths from NMS-PLAN Section 1.1
  - `getAllowedCommands()` — allowlist: `show interfaces, show ip route, show ip bgp summary, ping, traceroute`

**Device factory (`core/models/devices/`):**
- `DeviceFactory.php` — `create(array $device, SecretsManagerInterface $secrets): NetworkDeviceInterface` — loads credentials from Vault via device's vault path, returns correct adapter for vendor

**Cluster management (`core/models/devices/`):**
- `ClusterManager.php` — CRUD for `device_clusters` collection, member status polling, failover event recording in `cluster_events`

**Config backup (`core/models/devices/`):**
- `DeviceManager.php` — add: `backup(string $deviceId): string`, stores in `device_backups` collection

**API handlers (`api/handlers/`):**
- `devices/StatusHandler.php` — `GET /api/devices/{id}/status`
- `devices/BackupHandler.php` — `POST /api/devices/{id}/backup`
- `devices/BackupsHandler.php` — `GET /api/devices/{id}/backups`
- `devices/ExecuteHandler.php` — `POST /api/devices/{id}/execute` (enforces allowlist, requires `nms.device.execute` permission)
- `clusters/ListHandler.php` — `GET /api/clusters`
- `clusters/CreateHandler.php` — `POST /api/clusters`
- `clusters/ShowHandler.php` — `GET /api/clusters/{id}`
- `clusters/UpdateHandler.php` — `PUT /api/clusters/{id}`
- `clusters/StatusHandler.php` — `GET /api/clusters/{id}/status`
- `clusters/EventsHandler.php` — `GET /api/clusters/{id}/events`
- `clusters/FailoverHandler.php` — `POST /api/clusters/{id}/failover`

**Tests:**
- `unit/DeviceFactoryTest.php` — returns correct adapter class for each vendor string
- `unit/CommandAllowlistTest.php` — allowlist accepts valid commands, rejects all others for each vendor
- `unit/VendorAdapterTest.php` — circuit breaker state injected correctly per device
- `integration/MikroTikAdapterTest.php` — connects to real/mock MikroTik, `getInterfaces()`, `getRoutes()`, `getNeighborTable()`
- `integration/FortiGateAdapterTest.php` — connects to real/mock FortiGate, `getFirewallRules()`, `getHAStatus()`
- `integration/RetryCircuitBreakerTest.php` — adapter retries on timeout, circuit breaker opens after 5 failures

> **RISK GATE:** At least one vendor adapter (MikroTik or FortiGate) must connect to a real device and retrieve live data before Phase 4 begins.

### Exit criteria
- [ ] `DeviceFactory` returns correct adapter per vendor, credentials loaded from Vault
- [ ] MikroTik adapter: `getInterfaces()`, `getRoutes()`, `getNeighborTable()` return data from real device
- [ ] FortiGate adapter: `getFirewallRules()`, `getHAStatus()` return data from real device
- [ ] VyOS adapter: connects and retrieves configuration
- [ ] `POST /api/devices/{id}/execute` with disallowed command returns 422
- [ ] Circuit breaker opens after 5 consecutive failures, recovers after 60s cooldown
- [ ] HA cluster can be created with two member devices; `GET /api/clusters/{id}/status` polls both nodes
- [ ] All Phase 3 tests pass

---

## Phase 4: IPAM Core

**Depends on:** Phase 1, Phase 3 (device/cluster references for pools)
**Estimated size:** ~22K tokens

### What to build

**IPAM models (`core/models/ipam/`):**
- `PoolManager.php` — CRUD for `ip_blocks`, `ip_pools`, `ip_subnets` collections; updates `used_addresses` + `utilization_percent` on assign/release; `ip_version` required on all records
- `IPAllocator.php`:
  - `allocateNext(string $poolId, array $assignedTo, string $mac): array` — atomic `findOneAndUpdate` claim (see NMS-PLAN Section 2.3 workflow)
  - `assignSpecific(string $ip, string $poolId, array $assignedTo): array`
  - `release(string $ip): bool` — sets status=released, decrements pool counter
  - `markL3(string $ip, array $routingInfo): void` — sets `routing.static_route_added`, `routing.route_id`, `routing.neighbor_id`
  - On contention (two parallel requests): retry up to 3 times
- `SubnetCalculator.php` — CIDR math: `getUsableRange()`, `getFirstUsable()`, `getLastUsable()`, `getTotalAddresses()`, `isIPv6`: all-usable (no broadcast), IPv6 /64 prefix counting
- `ConflictChecker.php` — `checkConflict(string $ip, string $poolId): bool`, `checkBGPConflict(string $cidr, NetworkDeviceInterface $adapter): array` — calls `adapter->getBGPPrefixesForRange()`

**IPAM collections:**
- `ip_reservations` management in `PoolManager.php`
- `ip_assignment_history` written on every assign/release/modify

**API handlers (`api/handlers/ipam/`):**
- `blocks/ListHandler.php` — `GET /api/ipam/blocks`
- `blocks/CreateHandler.php` — `POST /api/ipam/blocks`
- `blocks/ShowHandler.php` — `GET /api/ipam/blocks/{id}`
- `pools/ListHandler.php` — `GET /api/ipam/pools` (filter by ip_version, site_id)
- `pools/CreateHandler.php` — `POST /api/ipam/pools`
- `pools/ShowHandler.php` — `GET /api/ipam/pools/{id}`
- `pools/UpdateHandler.php` — `PUT /api/ipam/pools/{id}`
- `pools/DeleteHandler.php` — `DELETE /api/ipam/pools/{id}`
- `pools/AvailableHandler.php` — `GET /api/ipam/pools/{id}/available`
- `pools/UsageHandler.php` — `GET /api/ipam/pools/{id}/usage`
- `assignments/ListHandler.php` — `GET /api/ipam/assignments`
- `assignments/CreateHandler.php` — `POST /api/ipam/assignments`
- `assignments/NextHandler.php` — `POST /api/ipam/assignments/next` (atomic allocation)
- `assignments/ShowHandler.php` — `GET /api/ipam/assignments/{ip}`
- `assignments/UpdateHandler.php` — `PUT /api/ipam/assignments/{ip}`
- `assignments/DeleteHandler.php` — `DELETE /api/ipam/assignments/{ip}` (release)
- `assignments/HistoryHandler.php` — `GET /api/ipam/assignments/{ip}/history`
- `SearchHandler.php` — `GET /api/ipam/search`
- `CalculateHandler.php` — `POST /api/ipam/calculate`
- `ValidateHandler.php` — `POST /api/ipam/validate`

**Tests:**
- `unit/SubnetCalculatorTest.php` — IPv4 usable range, IPv6 all-usable, /64 counting, network contains check
- `unit/IPAllocatorTest.php` — L2 vs L3 assignment difference (L3 sets routing fields)
- `unit/ConflictCheckerTest.php` — detects overlap within pool, calls BGP adapter method
- **`integration/AtomicAllocationTest.php`** — fires 10 parallel `POST /api/ipam/assignments/next` at same pool, asserts no two responses share an IP (validates `findOneAndUpdate` atomicity)
- `integration/IPAMLifecycleTest.php` — create block → pool → assign L2 → assign L3 → release → verify counters return to initial values

### Exit criteria
- [ ] IPv4 and IPv6 pools created and managed (ip_version field on all records)
- [ ] Concurrent atomic allocation: 10 parallel requests → 0 duplicate IPs
- [ ] L2 assignment: IP assigned, `routing.static_route_added = false`
- [ ] L3 assignment: IP assigned, `routing.static_route_added = true` (after route created in Phase 5)
- [ ] IPv6 /64 tracking: `allocated_prefixes` increments, `utilization_percent` based on /64 count
- [ ] Pool counters correct after assign + release cycle
- [ ] Assignment history written on every state change
- [ ] All Phase 4 tests pass

---

## Phase 5: Network Configuration

**Depends on:** Phase 3 (vendor adapters), Phase 4 (IPAM)
**Estimated size:** ~25K tokens

### What to build

**Route management (`core/models/routing/`):**
- `RouteManager.php` — CRUD for `static_routes` collection; `ip_version` required; writes `route_history` on every change
- `RouteSync.php` — `syncToDevice(string $routeId): bool` — pushes route to device via adapter, updates `synced_to_device` + `device_route_id`; if device is in cluster, pushes to `cluster.management_ip`
- `BGPMonitor.php` — read-only polling: `pollSessions(string $deviceId): void` — calls `adapter->getBGPSessions()`, upserts to `bgp_sessions` collection; `pollOSPF(string $deviceId): void`

**Neighbor management (`core/models/neighbors/`):**
- `NeighborManager.php` — CRUD for `neighbor_entries` collection; `protocol` field = "arp" (IPv4) or "ndp" (IPv6); writes to `mac_address_registry` on create
- `NeighborSync.php` — `syncToDevice(string $entryId): bool` — pushes static ARP/NDP entry via adapter; cluster-aware

**Firewall management (`core/models/firewall/`):**
- `PolicyManager.php` — CRUD for `firewall_policies`; `ip_version` required; writes `firewall_policy_history` on change
- `PolicyBuilder.php`:
  - `buildIPv4InboundPolicy(array $params): array` — creates VIP object + policy with `nat_enabled: true`, `nat_type: "destination"`
  - `buildIPv6InboundPolicy(array $params): array` — creates policy with `nat_enabled: false`, `vip_id: null` (direct address match)
  - `buildIPv4OutboundPolicy()` / `buildIPv6OutboundPolicy()`
- `ObjectManager.php` — CRUD for `firewall_address_objects`, `firewall_address_groups`, `firewall_service_objects`, `firewall_vips`
- VIPs: `firewall_vips` collection — always `ip_version: "ipv4"`, never created for IPv6

**API handlers (`api/handlers/`):**
- `routes/ListHandler.php` — `GET /api/routes` (filter by ip_version)
- `routes/CreateHandler.php` — `POST /api/routes`
- `routes/ShowHandler.php` — `GET /api/routes/{id}`
- `routes/DeleteHandler.php` — `DELETE /api/routes/{id}`
- `routes/SyncHandler.php` — `POST /api/routes/{id}/sync`
- `routes/DeviceRoutesHandler.php` — `GET /api/routes/device/{device_id}`
- `routing/BGPSessionsHandler.php` — `GET /api/routing/bgp/sessions`
- `routing/BGPDeviceHandler.php` — `GET /api/routing/bgp/sessions/{device_id}`
- `routing/BGPConflictsHandler.php` — `GET /api/routing/bgp/conflicts`
- `routing/OSPFHandler.php` — `GET /api/routing/ospf/neighbors`
- `neighbors/ListHandler.php` — `GET /api/neighbors` (filter by protocol: arp|ndp)
- `neighbors/CreateHandler.php` — `POST /api/neighbors`
- `neighbors/DeleteHandler.php` — `DELETE /api/neighbors/{id}`
- `neighbors/DeviceNeighborsHandler.php` — `GET /api/neighbors/device/{device_id}`
- `neighbors/SyncHandler.php` — `POST /api/neighbors/sync`
- `firewall/policies/ListHandler.php` — `GET /api/firewall/policies` (filter by ip_version, cluster_id)
- `firewall/policies/CreateHandler.php` — `POST /api/firewall/policies`
- `firewall/policies/ShowHandler.php` — `GET /api/firewall/policies/{id}`
- `firewall/policies/UpdateHandler.php` — `PUT /api/firewall/policies/{id}`
- `firewall/policies/DeleteHandler.php` — `DELETE /api/firewall/policies/{id}`
- `firewall/policies/SyncHandler.php` — `POST /api/firewall/policies/{id}/sync`
- `firewall/policies/ReorderHandler.php` — `POST /api/firewall/policies/reorder`
- `firewall/objects/AddressesHandler.php` — `GET /api/firewall/addresses`, `POST /api/firewall/addresses`
- `firewall/objects/ServicesHandler.php` — `GET /api/firewall/services`, `POST /api/firewall/services`
- `firewall/vips/ListHandler.php` — `GET /api/firewall/vips`
- `firewall/vips/CreateHandler.php` — `POST /api/firewall/vips`

**Tests:**
- `unit/PolicyBuilderIPv4Test.php` — builds policy with VIP, `nat_enabled: true`, `nat_type: "destination"`
- `unit/PolicyBuilderIPv6Test.php` — builds policy with `nat_enabled: false`, `vip_id: null`, NO VIP created
- `unit/RouteSyncTest.php` — cluster-aware: pushes to cluster management_ip, not individual device ip
- `integration/RouteLifecycleTest.php` — create route → sync to device → verify on device → delete → verify removed
- `integration/FirewallPolicyTest.php` — create IPv4 policy with VIP → sync → verify on device → delete
- `integration/BGPMonitorTest.php` — poll BGP sessions from real device, upsert to bgp_sessions collection

### Exit criteria
- [ ] Routes sync to device (IPv4 /32, IPv6 /128), stored in static_routes
- [ ] ARP entries sync to device (IPv4), NDP entries sync (IPv6)
- [ ] IPv4 firewall policy: VIP created + policy with DNAT
- [ ] IPv6 firewall policy: no VIP created, direct address matching, `nat_enabled: false`
- [ ] All config pushes (routes, neighbors, policies) go to `cluster.management_ip` when device is clustered
- [ ] BGP sessions polled and stored in `bgp_sessions` (read-only, no writes to device)
- [ ] BGP conflict check returns overlapping prefixes for a given CIDR
- [ ] Route history written on every create/delete
- [ ] All Phase 5 tests pass

---

## Phase 6: Provisioning Engine & IMS Integration

**Depends on:** Phase 4 (IPAM), Phase 5 (Network Config)
**Estimated size:** ~28K tokens

### What to build

**Provisioning engine (`core/models/provisioning/`):**
- `SagaExecutor.php`:
  - `execute(ProvisioningJob $job): void` — runs steps in order, populates `compensation.params` from `output_data` after each success
  - On step failure after max_retries: triggers `CompensationRunner`
  - Updates `provisioning_steps` collection in real-time
- `CompensationRunner.php`:
  - `compensate(string $jobId): void` — reverses all `completed` steps in reverse order
  - On compensation failure: adds to `manual_intervention_queue`, continues to next step
  - Uses idempotency keys on all compensation calls (prevent double-release)
  - Final job status: `compensated` or `partial_compensation`
- `ProvisioningEngine.php`:
  - `validatePhase0(array $request): array` — read-only checks: pool availability, device reachability, IP conflicts, BGP conflicts, cluster health
  - `provisionServer(array $request, string $idempotencyKey): ProvisioningJob` — dual-stack (IPv4 + IPv6 tracks per NMS-PLAN Section 10.3)
  - `deprovisionServer(string $serverId, string $idempotencyKey): ProvisioningJob`
  - All 13 saga steps: allocate L2 IPv4, allocate L3 IPv4, create /32 route, create ARP entry, create VIP, create inbound policy (IPv4), create outbound policy, repeat for IPv6 (no VIP, no NAT)
  - Each step has explicit compensating action (IP release, route delete, policy delete, etc.)

**NIC management (`core/models/nics/`):**
- `NicManager.php`:
  - `syncFromWebhook(string $imsServerId, array $nics): void` — creates/updates `server_nics` documents on `server.nic_change` webhook
  - `updatePortAssignment(string $nicId, array $connectivity): void` — updates connected_to (device, port, cable)
  - `linkIPAssignment(string $nicId, string $assignmentId): void`

**IMS integration (`core/auth/`):**
- `ImsTicketClient.php` — full implementation:
  - `createTicket(string $type, string $title, string $body, array $sourceRef): string` — POST to IMS via M2M token
  - `updateTicket(string $ticketId, array $updates): void`
  - `getTicket(string $ticketId): array`
  - Ticket types: `nms_intervention`, `nms_drift`, `nms_provision_failure`, `nms_device_unreachable`

**API handlers (`api/handlers/`):**
- `provision/ProvisionHandler.php` — `POST /api/provision/server` (requires `X-Idempotency-Key`)
- `provision/DeprovisionHandler.php` — `POST /api/provision/deprovision` (requires `X-Idempotency-Key`)
- `provision/CompensateHandler.php` — `POST /api/provision/jobs/{id}/compensate`
- `provision/JobsHandler.php` — `GET /api/provision/jobs`
- `provision/JobShowHandler.php` — `GET /api/provision/jobs/{id}` (all steps + compensation status)
- `provision/ManualQueueHandler.php` — `GET /api/provision/manual-queue`
- `provision/ManualQueueResolveHandler.php` — `PUT /api/provision/manual-queue/{id}/resolve`
- `nics/ListHandler.php` — `GET /api/nics`
- `nics/ShowHandler.php` — `GET /api/nics/{id}`
- `nics/UpdateHandler.php` — `PUT /api/nics/{id}`
- `nics/ServerNicsHandler.php` — `GET /api/nics/server/{ims_server_id}`
- `nics/SwitchNicsHandler.php` — `GET /api/nics/switch/{device_id}`
- `nics/SyncHandler.php` — `POST /api/nics/sync/{ims_server_id}`
- `integration/ims/ProvisionNetworkHandler.php` — `POST /api/integration/ims/provision-network`
- `integration/ims/DeprovisionNetworkHandler.php` — `POST /api/integration/ims/deprovision-network`
- `integration/ims/ServerNetworkHandler.php` — `GET /api/integration/ims/server/{id}/network`
- `integration/ims/ServerConnectionsHandler.php` — `GET /api/integration/ims/server/{id}/connections`
- `integration/ims/ValidateAvailabilityHandler.php` — `POST /api/integration/ims/validate-availability`

**Webhook receiver:**
- Route `POST /api/webhooks/ims` → dispatches `server.provision`, `server.deprovision`, `server.migrate`, `server.nic_change`, `server.reboot` events to correct handlers

**Tests:**
- `unit/SagaExecutorTest.php` — steps run in order, compensation.params populated from output_data
- `unit/CompensationRunnerTest.php` — reverses in reverse order, continues after compensation failure
- `unit/ProvisioningEnginePhase0Test.php` — validates catches unreachable device BEFORE any mutation
- **`integration/SagaFailureTest.php`** — provision → mock step 4 failure → verify steps 1-3 compensated (IPs released, routes deleted from DB and device)
- **`integration/CompensationFailureTest.php`** — compensation fails → item in manual_intervention_queue, job status = `partial_compensation`
- `integration/IdempotencyTest.php` — same `X-Idempotency-Key` returns same response without re-executing
- `integration/FullProvisionCycleTest.php` — full provision → deprovision against real device
- `integration/WebhookTest.php` — `server.nic_change` webhook updates `server_nics` correctly (mock IMS)

> **RISK GATE:** A complete provision → deprovision cycle must succeed against at least one real device before Phase 7.

### Exit criteria
- [ ] Full provisioning saga completes (IPv4 + IPv6 dual-stack)
- [ ] Step failure triggers compensation in correct reverse order
- [ ] Compensation failure: item added to `manual_intervention_queue`, saga continues
- [ ] Phase 0 pre-validation aborts provisioning if device unreachable (no mutations occurred)
- [ ] Idempotency key: duplicate provision request returns cached result, no re-execution
- [ ] `server.nic_change` webhook updates `server_nics` (MAC, port, VLAN, IPs)
- [ ] Manual queue items create IMS tickets via M2M `ImsTicketClient`
- [ ] Full provision → deprovision cycle verified on real device
- [ ] All Phase 6 tests pass

---

## Phase 7: Topology & Discovery

**Depends on:** Phase 2 (physical infra), Phase 3 (device adapters)
**Estimated size:** ~18K tokens

### What to build

**Topology models (`core/models/topology/`):**
- `NodeDiscovery.php` — for each device: calls `adapter->getInterfaces()`, `adapter->getNeighborDiscovery()`, `adapter->getNeighborTable()`, builds port list
- `LinkDiscovery.php` — matches CDP/LLDP/MikroTik neighbor data to registered devices, creates cable records for confirmed connections, flags unknown neighbors for review
- `TopologyBuilder.php` — aggregates into topology response format (Section 6.5): `{sites, nodes, links}` with cluster grouping, drift status on nodes
- Full `PathMaterializer.php` — path recomputation integration: on cable create/update/delete, invalidate affected paths, queue recomputation

**Services (`services/scheduler/`):**
- `TopologyRefresh.php` — periodic topology discovery runner
- `DevicePoller.php` — periodic device status checks (updates `device.status`, `device.last_seen`)
- `BackupScheduler.php` — periodic config backups per role interval
- `BGPPoller.php` — periodic BGP session polling (calls `BGPMonitor::pollSessions()`)
- `DriftScanner.php` — stub only (full impl in Phase 8)

**API handlers (`api/handlers/topology/`):**
- `LogicalHandler.php` — `GET /api/topology`
- `SiteTopologyHandler.php` — `GET /api/topology/site/{site_id}`
- `PhysicalHandler.php` — `GET /api/topology/physical`
- `DiscoverHandler.php` — `POST /api/topology/discover`
- `LayoutSaveHandler.php` — `POST /api/topology/layout/save`
- `SnapshotsHandler.php` — `GET /api/topology/snapshots`
- `SnapshotCreateHandler.php` — `POST /api/topology/snapshots`

**`topology_views` + `topology_snapshots` collections** managed in `TopologyBuilder.php`

**Tests:**
- `unit/TopologyBuilderTest.php` — aggregates sites + nodes + links into correct Section 6.5 format
- `unit/LinkDiscoveryTest.php` — LLDP neighbor matched to registered device creates cable record
- `integration/DiscoveryTest.php` — run discovery against real device → verify at least one neighbor connection detected
- `integration/TopologyAPITest.php` — `GET /api/topology/site/{id}` returns valid graph with nodes and links

### Exit criteria
- [ ] Discovery finds at least one new neighbor connection from a real device
- [ ] `GET /api/topology` returns valid graph: `{sites, nodes, links}` with drift status on nodes
- [ ] Cluster nodes shown as grouped with role (primary/secondary) in nodes response
- [ ] Topology snapshots created and retrievable
- [ ] `POST /api/topology/discover` triggers discovery and updates cable records
- [ ] All Phase 7 tests pass

---

## Phase 8: Monitoring + Drift Detection

**Depends on:** Phase 3 (device adapters), Phase 7 (topology)
**Estimated size:** ~18K tokens

### What to build

**Monitoring (`core/models/monitoring/`):**
- `ZabbixClient.php`:
  - `getHostHealth(string $zabbixHostId): array` — `host.get` + `item.get` for CPU/memory/uptime, cached 60s Redis TTL
  - `getInterfaceTraffic(string $zabbixHostId): array` — traffic bps in/out per interface, 60s TTL
  - `getActiveAlerts(string $zabbixHostId): array` — `trigger.get` for active problems, 30s TTL
  - `getAvailability(string $zabbixHostId): bool` — 30s TTL
  - All methods check Redis cache first (`monitoring:{zabbixHostId}:{type}`), fetch from Zabbix on miss

**Drift detection (`core/models/drift/`):**
- `DriftDetector.php`:
  - `scanDevice(string $deviceId): ?array` — calls `adapter->getConfigSections()`, compares section-by-section against NMS expected state (from MongoDB)
  - `compareSection(string $section, array $deviceState, array $nmsState): array` — field-by-field diff, returns `[{section, action, identifier, device_value, nms_value}]`
  - On diff found: writes `config_drift_log` entry, updates `device.drift.status = "drifted"`, updates `device.drift.open_drift_count`
  - `resolveAsPush(string $driftId): void` — push NMS state to device (calls appropriate adapter sync methods)
  - `resolveAsPull(string $driftId): void` — accept device state into NMS (update MongoDB to match device)
  - `resolveAsIgnore(string $driftId): void` — marks drift ignored, doesn't change NMS state

**Services:**
- `DriftScanner.php` — full implementation: polls all online devices, respects poll interval per role (edge_firewall: 5min, core_router: 10min, access_switch: 30min, offline: skip), creates IMS ticket on new drift if `requires_approval: true`

**API handlers (`api/handlers/`):**
- `drift/ListHandler.php` — `GET /api/drift`
- `drift/DeviceDriftHandler.php` — `GET /api/devices/{id}/drift`
- `drift/ScanHandler.php` — `POST /api/devices/{id}/drift/scan`
- `drift/ResolveHandler.php` — `POST /api/drift/{id}/resolve` (body: `{"action": "push"|"pull"|"ignore"}`)
- `monitoring/DeviceHealthHandler.php` — `GET /api/monitoring/device/{id}/health`
- `monitoring/DeviceTrafficHandler.php` — `GET /api/monitoring/device/{id}/traffic`
- `monitoring/DeviceAlertsHandler.php` — `GET /api/monitoring/device/{id}/alerts`
- `monitoring/OverviewHandler.php` — `GET /api/monitoring/overview`

**Tests:**
- `unit/DriftDetectorTest.php` — `compareSection` detects added/removed/modified entries correctly
- `unit/ZabbixCacheTest.php` — returns cached data within TTL, fetches fresh after TTL expires
- `integration/ZabbixClientTest.php` — retrieves real health data from Zabbix for a mapped device
- **`integration/DriftDetectionTest.php`** — manually add firewall rule directly on device → `DriftDetector::scanDevice()` detects it → resolve as "pull" → NMS database updated to match device

### Exit criteria
- [ ] Zabbix health data (CPU, memory, uptime) displayed for managed devices
- [ ] Redis caching: TTL enforced (60s health, 30s alerts), stale data not served after expiry
- [ ] Drift scanner detects manually added firewall rule on a real device
- [ ] Drift resolution: "push" overwrites device with NMS state, "pull" updates NMS from device, "ignore" closes without change
- [ ] `device.drift.status` + `open_drift_count` updated correctly
- [ ] New unreachable device (>15 min) creates `nms_device_unreachable` ticket in IMS
- [ ] All Phase 8 tests pass

---

## Phase 9: VPN & Additional Vendors

**Depends on:** Phase 3, Phase 5
**Estimated size:** ~20K tokens

### What to build

**VPN models (`core/models/` — add vpn directory):**
- VPN collections: `vpn_gateways`, `vpn_tunnels`, `vpn_users`
- `VpnGatewayManager.php` — CRUD, PSK stored in Vault (vault path reference in MongoDB, never the PSK itself)
- `VpnTunnelManager.php` — CRUD, PSK in Vault, status polling
- `VpnUserManager.php` — CRUD, passwords hashed with Argon2id (not encrypted)

**Additional vendor adapters:**
- `vendors/cisco/CiscoRESTCONF.php` — raw RESTCONF client (IETF yang headers from NMS-PLAN Section 1.1)
- `vendors/cisco/CiscoAdapter.php` — implements `NetworkDeviceInterface`, allowlist: `show ip route, show interfaces, show ip bgp summary, ping, traceroute`
- `vendors/aruba/ArubaAPI.php` — session-based auth client (login → cookie → requests → logout)
- `vendors/aruba/ArubaAdapter.php` — implements `NetworkDeviceInterface`, allowlist: `show interfaces, show ip route, show vlan, ping, traceroute`
- Register both in `DeviceFactory.php`

**API handlers (`api/handlers/vpn/`):**
- `GatewaysHandler.php` — `GET /api/vpn/gateways`, `POST /api/vpn/gateways`
- `TunnelsHandler.php` — `GET /api/vpn/tunnels`, `POST /api/vpn/tunnels`
- `UsersListHandler.php` — `GET /api/vpn/users`
- `UsersCreateHandler.php` — `POST /api/vpn/users`
- `UsersDeleteHandler.php` — `DELETE /api/vpn/users/{id}`

**Audit + Settings endpoints (complete remaining handlers):**
- `audit/LogsHandler.php` — `GET /api/audit/logs` (filterable)
- `audit/LogsExportHandler.php` — `GET /api/audit/logs/export`
- `audit/ChangesHandler.php` — `GET /api/audit/changes/{resource}`
- `settings/SecretsHealthHandler.php` — `GET /api/settings/secrets/health`
- `settings/RedisHealthHandler.php` — `GET /api/settings/redis/health`

**Tests:**
- `unit/VpnUserPasswordTest.php` — password stored as Argon2id hash, never plaintext
- `unit/VpnPSKTest.php` — PSK stored as Vault reference in MongoDB, not the PSK string
- `integration/CiscoAdapterTest.php` — connect to Cisco device (if available), retrieve routes + interfaces
- `integration/ArubaAdapterTest.php` — connect to Aruba device (if available), retrieve VLANs + interfaces
- `integration/VpnLifecycleTest.php` — create gateway → create tunnel → check status

### Exit criteria
- [ ] VPN PSKs stored in Vault, MongoDB holds only vault path reference
- [ ] VPN user passwords stored as Argon2id hash
- [ ] VPN tunnel status checkable via status field (up/down/establishing/unknown)
- [ ] At least one additional vendor adapter (Cisco or Aruba) connects and retrieves data
- [ ] Audit log query + export endpoints functional
- [ ] All Phase 9 tests pass

---

## Phase 10: Frontend Development

**Depends on:** All backend phases (1–9) complete
**Estimated size:** ~30K tokens

### What to build

**Base layout (`views/layout/`):**
- `_base.php` — HTML shell, CDN links for Tailwind CSS, Alpine.js, Cytoscape.js, Chart.js, Three.js
- `_sidebar.php` — navigation sidebar with all routes from Section 15.2
- `_header.php` — top bar: breadcrumb, current user (from JWT claims via `app.js`)
- `_flash.php` — toast/flash message partial

**Partials (`views/partials/`):**
- `_table.php` — reusable sortable/filterable data table (Alpine.js `x-data`)
- `_modal.php` — Alpine.js `x-show` modal wrapper
- `_badge.php` — status badge (color-coded: online/offline/drifted/clean)
- `_confirm.php` — delete confirmation dialog
- `_pagination.php` — pagination controls

**Global JS (`public/js/`):**
- `app.js` — Alpine.js global store (`$store.auth`, `$store.user`), JWT refresh interceptor (auto-refresh before expiry), fetch helper with auth header injection
- `topology.js` — Cytoscape.js init, layout (cose-bilkent), cluster node grouping, drift status coloring, layer toggle
- `rack-view.js` — Three.js or SVG rack elevation diagram with U-slot positioning
- `charts.js` — Chart.js initialization helpers (utilization gauges, traffic charts)
- `ipam-grid.js` — IP grid rendering: color-coded cells (available/assigned/reserved)

**Pages (strictly from Section 15.2 — all 30+ pages):**

Auth:
- `views/auth/login.php`

Dashboard:
- `views/dashboard/index.php` — health overview, site map (inline SVG or Leaflet), IPAM utilization (Chart.js), drift alerts, recent jobs, recent audit entries, quick actions

Devices + Clusters:
- `views/devices/index.php` — list with cluster badges, drift indicators
- `views/devices/show.php` — ports, connections, config sections, drift status
- `views/devices/ports.php` — port management + cable trace
- `views/clusters/index.php`
- `views/clusters/show.php` — member status, failover history

Drift:
- `views/drift/index.php` — all open drifts
- `views/drift/show.php` — diff view (Alpine.js diff toggle) + resolve actions (push/pull/ignore)

Sites + Racks:
- `views/sites/index.php` — world/regional map with site markers
- `views/sites/show.php` — racks, devices, connectivity
- `views/racks/show.php` — rack elevation (Three.js or pure CSS/SVG U-diagram)
- `views/racks/cables.php`

IPAM:
- `views/ipam/index.php` — pool utilization overview (Chart.js per pool, IPv4+IPv6)
- `views/ipam/pools/index.php`
- `views/ipam/pools/show.php` — IP grid (Alpine.js rendered, color-coded cells via `ipam-grid.js`)
- `views/ipam/assignments.php`

Firewall:
- `views/firewall/policies/index.php` — filterable by ip_version
- `views/firewall/policies/edit.php` — dynamic rule builder (Alpine.js dynamic rows for sources/destinations/services)
- `views/firewall/objects/index.php`

Routes + BGP:
- `views/routes/index.php`
- `views/routing/bgp.php` — BGP session monitoring dashboard
- `views/routing/conflicts.php`

Neighbors + Topology:
- `views/neighbors/index.php`
- `views/topology/logical.php` — Cytoscape.js canvas, layer toggles, cable trace on port click
- `views/topology/physical.php` — racks + cables, patch panel port mapping
- `views/topology/site.php`

NICs:
- `views/nics/index.php` — all server NICs, filterable by site/switch/VLAN
- `views/nics/server.php` — NICs for a specific server (connectivity + IP assignments)

VPN:
- `views/vpn/tunnels.php`
- `views/vpn/users.php`

Provisioning:
- `views/provision/index.php` — active jobs, history
- `views/provision/show.php` — step-by-step saga progress + compensation status
- `views/provision/manual-queue.php` — operator intervention queue

Audit + Settings:
- `views/audit/index.php`
- `views/settings/index.php`
- `views/settings/zabbix.php`
- `views/settings/vault.php`
- `views/settings/vendors.php` — vendor API credential health (no user/role pages — IMS owns those)

**Public entry point:**
- `public/index.php` — web entry point: authenticates session, routes to correct view, passes data from API layer

**Tests:**
- `tests/api/AuthFlowTest.php` — login → get token → access protected endpoint → logout
- `tests/api/FullE2ETest.php` — login → provision server → track job progress → verify completion
- `tests/api/DriftE2ETest.php` — drift detected → view diff → resolve → verify device updated
- `tests/api/NicWebhookE2ETest.php` — `server.nic_change` webhook → NIC record updates in `/api/nics/server/{id}`

### Exit criteria
- [ ] All pages render with correct data from backend APIs
- [ ] Login → JWT stored in Alpine.js store → auto-refreshed before expiry
- [ ] Topology visualization (Cytoscape.js): devices, links, cluster pairs, drift color-coding
- [ ] Rack elevation view: correct U-slot placement with patch panels
- [ ] Cable trace: click port → shows full materialized path through patch panels
- [ ] IP grid: color-coded cells (assigned/available/reserved), both IPv4 + IPv6 pools
- [ ] Firewall policy editor: dynamic rows, ip_version selector changes form (no VIP fields for IPv6)
- [ ] NIC page: server → cable → switch port connectivity shown correctly
- [ ] Provisioning job page: real-time step progress, compensation status shown per step
- [ ] Manual intervention queue actionable by operator
- [ ] No user/role management pages in NMS (IMS owns those)
- [ ] All E2E tests pass

---

## Quick Reference: Scope Rules

| Rule | Detail |
|------|--------|
| **Only build what's in NMS-PLAN.md** | No extra features, helpers, or pages beyond the spec |
| **No RBAC DB in NMS** | Only read `permissions[]` from JWT claim |
| **No ticketing in NMS** | Use `ImsTicketClient` → IMS system |
| **No user/role pages** | These live in IMS only |
| **Secrets never in MongoDB** | Only Vault path references |
| **ip_version on all IP records** | Every ip_* collection requires this field |
| **IPv6 = no VIP, no NAT** | Direct address matching only |
| **Cluster-aware pushes** | Always push to `cluster.management_ip`, not individual node |
| **Atomic IP allocation** | `findOneAndUpdate` only — no find-then-update pattern |
| **Saga compensation is idempotent** | Check before delete/release to prevent double-action |
