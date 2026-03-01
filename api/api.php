<?php

declare(strict_types=1);

/**
 * NMS API Router
 *
 * Parses HTTP method + path, applies middleware stack, routes to handler.
 * Entry point: called from public/index.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Logger;

// Bootstrap application config
require_once dirname(__DIR__) . '/core/config/app.php';

// Set JSON content type for all API responses
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Parse request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// Strip /api prefix
if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4) ?: '/';
}

// Parse request body
$body = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $rawBody = file_get_contents('php://input');
    $body    = json_decode($rawBody ?: '{}', true) ?? [];
}

// Build request context
$request = [
    'method'  => $method,
    'uri'     => $uri,
    'query'   => $_GET,
    'body'    => $body,
    'headers' => getallheaders(),
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
];

Logger::addContext(['request_id' => bin2hex(random_bytes(8)), 'method' => $method, 'uri' => $uri]);

// ─── Route table ─────────────────────────────────────────────────────────────

$routes = [
    // Auth (no auth middleware on login)
    'POST /auth/login'    => ['handler' => 'auth/LoginHandler',   'auth' => false],
    'POST /auth/refresh'  => ['handler' => 'auth/RefreshHandler', 'auth' => false],
    'POST /auth/logout'   => ['handler' => 'auth/LogoutHandler',  'auth' => true],
    'GET /auth/me'        => ['handler' => 'auth/MeHandler',      'auth' => true],
    'POST /auth/m2m/token'=> ['handler' => 'auth/M2MTokenHandler','auth' => true, 'permission' => 'nms.settings.write'],

    // Devices
    'GET /devices'                => ['handler' => 'devices/ListHandler',    'auth' => true, 'permission' => 'nms.device.read'],
    'POST /devices'               => ['handler' => 'devices/CreateHandler',  'auth' => true, 'permission' => 'nms.device.write'],
    'GET /devices/{id}'           => ['handler' => 'devices/ShowHandler',    'auth' => true, 'permission' => 'nms.device.read'],
    'PUT /devices/{id}'           => ['handler' => 'devices/UpdateHandler',  'auth' => true, 'permission' => 'nms.device.write'],
    'DELETE /devices/{id}'        => ['handler' => 'devices/DeleteHandler',  'auth' => true, 'permission' => 'nms.device.delete'],
    'GET /devices/{id}/status'    => ['handler' => 'devices/StatusHandler',  'auth' => true, 'permission' => 'nms.device.read'],
    'GET /devices/{id}/ports'     => ['handler' => 'devices/PortsHandler',   'auth' => true, 'permission' => 'nms.device.read'],
    'POST /devices/{id}/backup'   => ['handler' => 'devices/BackupHandler',  'auth' => true, 'permission' => 'nms.device.write'],
    'GET /devices/{id}/backups'   => ['handler' => 'devices/BackupsHandler', 'auth' => true, 'permission' => 'nms.device.read'],
    'POST /devices/{id}/execute'  => ['handler' => 'devices/ExecuteHandler', 'auth' => true, 'permission' => 'nms.device.execute'],
    'GET /devices/{id}/drift'     => ['handler' => 'drift/DeviceDriftHandler','auth' => true, 'permission' => 'nms.drift.read'],
    'POST /devices/{id}/drift/scan'=> ['handler' => 'drift/ScanHandler',     'auth' => true, 'permission' => 'nms.drift.read'],

    // Clusters
    'GET /clusters'              => ['handler' => 'clusters/ListHandler',     'auth' => true, 'permission' => 'nms.cluster.read'],
    'POST /clusters'             => ['handler' => 'clusters/CreateHandler',   'auth' => true, 'permission' => 'nms.cluster.write'],
    'GET /clusters/{id}'         => ['handler' => 'clusters/ShowHandler',     'auth' => true, 'permission' => 'nms.cluster.read'],
    'PUT /clusters/{id}'         => ['handler' => 'clusters/UpdateHandler',   'auth' => true, 'permission' => 'nms.cluster.write'],
    'GET /clusters/{id}/status'  => ['handler' => 'clusters/StatusHandler',   'auth' => true, 'permission' => 'nms.cluster.read'],
    'GET /clusters/{id}/events'  => ['handler' => 'clusters/EventsHandler',   'auth' => true, 'permission' => 'nms.cluster.read'],
    'POST /clusters/{id}/failover'=> ['handler' => 'clusters/FailoverHandler','auth' => true, 'permission' => 'nms.cluster.write'],

    // Drift
    'GET /drift'                 => ['handler' => 'drift/ListHandler',        'auth' => true, 'permission' => 'nms.drift.read'],
    'POST /drift/{id}/resolve'   => ['handler' => 'drift/ResolveHandler',     'auth' => true, 'permission' => 'nms.drift.resolve'],

    // IPAM — Blocks
    'GET /ipam/blocks'           => ['handler' => 'ipam/blocks/ListHandler',  'auth' => true, 'permission' => 'nms.ipam.read'],
    'POST /ipam/blocks'          => ['handler' => 'ipam/blocks/CreateHandler','auth' => true, 'permission' => 'nms.ipam.write'],
    'GET /ipam/blocks/{id}'      => ['handler' => 'ipam/blocks/ShowHandler',  'auth' => true, 'permission' => 'nms.ipam.read'],

    // IPAM — Pools
    'GET /ipam/pools'                   => ['handler' => 'ipam/pools/ListHandler',     'auth' => true, 'permission' => 'nms.ipam.read'],
    'POST /ipam/pools'                  => ['handler' => 'ipam/pools/CreateHandler',   'auth' => true, 'permission' => 'nms.ipam.write'],
    'GET /ipam/pools/{id}'              => ['handler' => 'ipam/pools/ShowHandler',     'auth' => true, 'permission' => 'nms.ipam.read'],
    'PUT /ipam/pools/{id}'              => ['handler' => 'ipam/pools/UpdateHandler',   'auth' => true, 'permission' => 'nms.ipam.write'],
    'DELETE /ipam/pools/{id}'           => ['handler' => 'ipam/pools/DeleteHandler',   'auth' => true, 'permission' => 'nms.ipam.write'],
    'GET /ipam/pools/{id}/available'    => ['handler' => 'ipam/pools/AvailableHandler','auth' => true, 'permission' => 'nms.ipam.read'],
    'GET /ipam/pools/{id}/usage'        => ['handler' => 'ipam/pools/UsageHandler',    'auth' => true, 'permission' => 'nms.ipam.read'],

    // IPAM — Assignments
    'GET /ipam/assignments'              => ['handler' => 'ipam/assignments/ListHandler',   'auth' => true, 'permission' => 'nms.ipam.read'],
    'POST /ipam/assignments'             => ['handler' => 'ipam/assignments/CreateHandler', 'auth' => true, 'permission' => 'nms.ipam.assign'],
    'POST /ipam/assignments/next'        => ['handler' => 'ipam/assignments/NextHandler',   'auth' => true, 'permission' => 'nms.ipam.assign'],
    'GET /ipam/assignments/{ip}'         => ['handler' => 'ipam/assignments/ShowHandler',   'auth' => true, 'permission' => 'nms.ipam.read'],
    'PUT /ipam/assignments/{ip}'         => ['handler' => 'ipam/assignments/UpdateHandler', 'auth' => true, 'permission' => 'nms.ipam.write'],
    'DELETE /ipam/assignments/{ip}'      => ['handler' => 'ipam/assignments/DeleteHandler', 'auth' => true, 'permission' => 'nms.ipam.write'],
    'GET /ipam/assignments/{ip}/history' => ['handler' => 'ipam/assignments/HistoryHandler','auth' => true, 'permission' => 'nms.ipam.read'],
    'GET /ipam/search'                   => ['handler' => 'ipam/SearchHandler',             'auth' => true, 'permission' => 'nms.ipam.read'],
    'POST /ipam/calculate'               => ['handler' => 'ipam/CalculateHandler',          'auth' => true, 'permission' => 'nms.ipam.read'],
    'POST /ipam/validate'                => ['handler' => 'ipam/ValidateHandler',           'auth' => true, 'permission' => 'nms.ipam.read'],

    // Firewall
    'GET /firewall/policies'              => ['handler' => 'firewall/policies/ListHandler',  'auth' => true, 'permission' => 'nms.firewall.read'],
    'POST /firewall/policies'             => ['handler' => 'firewall/policies/CreateHandler','auth' => true, 'permission' => 'nms.firewall.write'],
    'GET /firewall/policies/{id}'         => ['handler' => 'firewall/policies/ShowHandler',  'auth' => true, 'permission' => 'nms.firewall.read'],
    'PUT /firewall/policies/{id}'         => ['handler' => 'firewall/policies/UpdateHandler','auth' => true, 'permission' => 'nms.firewall.write'],
    'DELETE /firewall/policies/{id}'      => ['handler' => 'firewall/policies/DeleteHandler','auth' => true, 'permission' => 'nms.firewall.write'],
    'POST /firewall/policies/{id}/sync'   => ['handler' => 'firewall/policies/SyncHandler',  'auth' => true, 'permission' => 'nms.firewall.sync'],
    'POST /firewall/policies/reorder'     => ['handler' => 'firewall/policies/ReorderHandler','auth' => true, 'permission' => 'nms.firewall.write'],
    'GET /firewall/addresses'             => ['handler' => 'firewall/objects/AddressesHandler','auth' => true, 'permission' => 'nms.firewall.read'],
    'POST /firewall/addresses'            => ['handler' => 'firewall/objects/AddressesHandler','auth' => true, 'permission' => 'nms.firewall.write'],
    'GET /firewall/services'              => ['handler' => 'firewall/objects/ServicesHandler', 'auth' => true, 'permission' => 'nms.firewall.read'],
    'POST /firewall/services'             => ['handler' => 'firewall/objects/ServicesHandler', 'auth' => true, 'permission' => 'nms.firewall.write'],
    'GET /firewall/vips'                  => ['handler' => 'firewall/vips/ListHandler',       'auth' => true, 'permission' => 'nms.firewall.read'],
    'POST /firewall/vips'                 => ['handler' => 'firewall/vips/CreateHandler',     'auth' => true, 'permission' => 'nms.firewall.write'],

    // Routes
    'GET /routes'                   => ['handler' => 'routes/ListHandler',       'auth' => true, 'permission' => 'nms.route.read'],
    'POST /routes'                  => ['handler' => 'routes/CreateHandler',     'auth' => true, 'permission' => 'nms.route.write'],
    'GET /routes/{id}'              => ['handler' => 'routes/ShowHandler',       'auth' => true, 'permission' => 'nms.route.read'],
    'DELETE /routes/{id}'           => ['handler' => 'routes/DeleteHandler',     'auth' => true, 'permission' => 'nms.route.write'],
    'POST /routes/{id}/sync'        => ['handler' => 'routes/SyncHandler',       'auth' => true, 'permission' => 'nms.route.sync'],
    'GET /routes/device/{device_id}'=> ['handler' => 'routes/DeviceRoutesHandler','auth' => true, 'permission' => 'nms.route.read'],
    'GET /routing/bgp/sessions'     => ['handler' => 'routing/BGPSessionsHandler','auth' => true, 'permission' => 'nms.route.read'],
    'GET /routing/bgp/sessions/{device_id}' => ['handler' => 'routing/BGPDeviceHandler','auth' => true, 'permission' => 'nms.route.read'],
    'GET /routing/bgp/conflicts'    => ['handler' => 'routing/BGPConflictsHandler','auth' => true, 'permission' => 'nms.route.read'],
    'GET /routing/ospf/neighbors'   => ['handler' => 'routing/OSPFHandler',     'auth' => true, 'permission' => 'nms.route.read'],

    // Neighbors (ARP + NDP)
    'GET /neighbors'                    => ['handler' => 'neighbors/ListHandler',         'auth' => true, 'permission' => 'nms.route.read'],
    'POST /neighbors'                   => ['handler' => 'neighbors/CreateHandler',       'auth' => true, 'permission' => 'nms.route.write'],
    'DELETE /neighbors/{id}'            => ['handler' => 'neighbors/DeleteHandler',       'auth' => true, 'permission' => 'nms.route.write'],
    'GET /neighbors/device/{device_id}' => ['handler' => 'neighbors/DeviceNeighborsHandler','auth' => true, 'permission' => 'nms.route.read'],
    'POST /neighbors/sync'              => ['handler' => 'neighbors/SyncHandler',         'auth' => true, 'permission' => 'nms.route.sync'],

    // Sites
    'GET /sites'              => ['handler' => 'infrastructure/sites/ListHandler',  'auth' => true, 'permission' => 'nms.device.read'],
    'POST /sites'             => ['handler' => 'infrastructure/sites/CreateHandler','auth' => true, 'permission' => 'nms.device.write'],
    'GET /sites/{id}'         => ['handler' => 'infrastructure/sites/ShowHandler',  'auth' => true, 'permission' => 'nms.device.read'],
    'PUT /sites/{id}'         => ['handler' => 'infrastructure/sites/UpdateHandler','auth' => true, 'permission' => 'nms.device.write'],

    // Racks
    'GET /racks'                  => ['handler' => 'infrastructure/racks/ListHandler',   'auth' => true, 'permission' => 'nms.device.read'],
    'POST /racks'                 => ['handler' => 'infrastructure/racks/CreateHandler', 'auth' => true, 'permission' => 'nms.device.write'],
    'GET /racks/{id}'             => ['handler' => 'infrastructure/racks/ShowHandler',   'auth' => true, 'permission' => 'nms.device.read'],
    'PUT /racks/{id}'             => ['handler' => 'infrastructure/racks/UpdateHandler', 'auth' => true, 'permission' => 'nms.device.write'],
    'GET /racks/{id}/diagram'     => ['handler' => 'infrastructure/racks/DiagramHandler','auth' => true, 'permission' => 'nms.device.read'],

    // Cables
    'GET /cables'                           => ['handler' => 'infrastructure/cables/ListHandler',       'auth' => true, 'permission' => 'nms.device.read'],
    'POST /cables'                          => ['handler' => 'infrastructure/cables/CreateHandler',     'auth' => true, 'permission' => 'nms.device.write'],
    'GET /cables/{id}'                      => ['handler' => 'infrastructure/cables/ShowHandler',       'auth' => true, 'permission' => 'nms.device.read'],
    'PUT /cables/{id}'                      => ['handler' => 'infrastructure/cables/UpdateHandler',     'auth' => true, 'permission' => 'nms.device.write'],
    'DELETE /cables/{id}'                   => ['handler' => 'infrastructure/cables/DeleteHandler',     'auth' => true, 'permission' => 'nms.device.write'],
    'GET /cables/device/{device_id}'        => ['handler' => 'infrastructure/cables/DeviceCablesHandler','auth' => true, 'permission' => 'nms.device.read'],
    'GET /cables/trace/{device_id}/{port}'  => ['handler' => 'infrastructure/cables/TraceHandler',      'auth' => true, 'permission' => 'nms.device.read'],
    'GET /cables/impact/{cable_id}'         => ['handler' => 'infrastructure/cables/ImpactHandler',     'auth' => true, 'permission' => 'nms.device.read'],

    // Topology
    'GET /topology'                  => ['handler' => 'topology/LogicalHandler',        'auth' => true, 'permission' => 'nms.topology.read'],
    'GET /topology/site/{site_id}'   => ['handler' => 'topology/SiteTopologyHandler',  'auth' => true, 'permission' => 'nms.topology.read'],
    'GET /topology/physical'         => ['handler' => 'topology/PhysicalHandler',       'auth' => true, 'permission' => 'nms.topology.read'],
    'POST /topology/discover'        => ['handler' => 'topology/DiscoverHandler',       'auth' => true, 'permission' => 'nms.topology.discover'],
    'POST /topology/layout/save'     => ['handler' => 'topology/LayoutSaveHandler',     'auth' => true, 'permission' => 'nms.topology.read'],
    'GET /topology/snapshots'        => ['handler' => 'topology/SnapshotsHandler',      'auth' => true, 'permission' => 'nms.topology.read'],
    'POST /topology/snapshots'       => ['handler' => 'topology/SnapshotCreateHandler', 'auth' => true, 'permission' => 'nms.topology.read'],

    // Monitoring
    'GET /monitoring/device/{id}/health'  => ['handler' => 'monitoring/DeviceHealthHandler',  'auth' => true, 'permission' => 'nms.device.read'],
    'GET /monitoring/device/{id}/traffic' => ['handler' => 'monitoring/DeviceTrafficHandler', 'auth' => true, 'permission' => 'nms.device.read'],
    'GET /monitoring/device/{id}/alerts'  => ['handler' => 'monitoring/DeviceAlertsHandler',  'auth' => true, 'permission' => 'nms.device.read'],
    'GET /monitoring/overview'            => ['handler' => 'monitoring/OverviewHandler',       'auth' => true, 'permission' => 'nms.device.read'],

    // VPN
    'GET /vpn/gateways'           => ['handler' => 'vpn/GatewaysHandler',    'auth' => true, 'permission' => 'nms.vpn.read'],
    'POST /vpn/gateways'          => ['handler' => 'vpn/GatewaysHandler',    'auth' => true, 'permission' => 'nms.vpn.write'],
    'GET /vpn/tunnels'            => ['handler' => 'vpn/TunnelsHandler',     'auth' => true, 'permission' => 'nms.vpn.read'],
    'POST /vpn/tunnels'           => ['handler' => 'vpn/TunnelsHandler',     'auth' => true, 'permission' => 'nms.vpn.write'],
    'GET /vpn/users'              => ['handler' => 'vpn/UsersListHandler',   'auth' => true, 'permission' => 'nms.vpn.read'],
    'POST /vpn/users'             => ['handler' => 'vpn/UsersCreateHandler', 'auth' => true, 'permission' => 'nms.vpn.write'],
    'DELETE /vpn/users/{id}'      => ['handler' => 'vpn/UsersDeleteHandler', 'auth' => true, 'permission' => 'nms.vpn.write'],

    // Audit
    'GET /audit/logs'             => ['handler' => 'audit/LogsHandler',       'auth' => true, 'permission' => 'nms.audit.read'],
    'GET /audit/logs/export'      => ['handler' => 'audit/LogsExportHandler', 'auth' => true, 'permission' => 'nms.audit.export'],
    'GET /audit/changes/{resource}'=> ['handler' => 'audit/ChangesHandler',   'auth' => true, 'permission' => 'nms.audit.read'],

    // Provisioning
    'POST /provision/server'                => ['handler' => 'provision/ProvisionHandler',          'auth' => true, 'permission' => 'nms.provision.execute', 'idempotent' => true],
    'POST /provision/deprovision'           => ['handler' => 'provision/DeprovisionHandler',        'auth' => true, 'permission' => 'nms.provision.execute', 'idempotent' => true],
    'POST /provision/jobs/{id}/compensate'  => ['handler' => 'provision/CompensateHandler',         'auth' => true, 'permission' => 'nms.provision.rollback'],
    'GET /provision/jobs'                   => ['handler' => 'provision/JobsHandler',               'auth' => true, 'permission' => 'nms.provision.execute'],
    'GET /provision/jobs/{id}'              => ['handler' => 'provision/JobShowHandler',            'auth' => true, 'permission' => 'nms.provision.execute'],
    'GET /provision/manual-queue'           => ['handler' => 'provision/ManualQueueHandler',        'auth' => true, 'permission' => 'nms.provision.rollback'],
    'PUT /provision/manual-queue/{id}/resolve'=> ['handler' => 'provision/ManualQueueResolveHandler','auth' => true, 'permission' => 'nms.provision.rollback'],

    // NICs
    'GET /nics'                         => ['handler' => 'nics/ListHandler',         'auth' => true, 'permission' => 'nms.nic.read'],
    'GET /nics/{id}'                    => ['handler' => 'nics/ShowHandler',         'auth' => true, 'permission' => 'nms.nic.read'],
    'PUT /nics/{id}'                    => ['handler' => 'nics/UpdateHandler',       'auth' => true, 'permission' => 'nms.nic.write'],
    'GET /nics/server/{ims_server_id}'  => ['handler' => 'nics/ServerNicsHandler',  'auth' => true, 'permission' => 'nms.nic.read'],
    'GET /nics/switch/{device_id}'      => ['handler' => 'nics/SwitchNicsHandler',  'auth' => true, 'permission' => 'nms.nic.read'],
    'POST /nics/sync/{ims_server_id}'   => ['handler' => 'nics/SyncHandler',        'auth' => true, 'permission' => 'nms.nic.write'],

    // IMS Integration
    'POST /integration/ims/provision-network'    => ['handler' => 'integration/ims/ProvisionNetworkHandler',   'auth' => true, 'permission' => 'nms.provision.execute'],
    'POST /integration/ims/deprovision-network'  => ['handler' => 'integration/ims/DeprovisionNetworkHandler', 'auth' => true, 'permission' => 'nms.provision.execute'],
    'GET /integration/ims/server/{id}/network'   => ['handler' => 'integration/ims/ServerNetworkHandler',      'auth' => true, 'permission' => 'nms.device.read'],
    'GET /integration/ims/server/{id}/connections'=> ['handler' => 'integration/ims/ServerConnectionsHandler', 'auth' => true, 'permission' => 'nms.device.read'],
    'POST /integration/ims/validate-availability' => ['handler' => 'integration/ims/ValidateAvailabilityHandler','auth' => true, 'permission' => 'nms.ipam.read'],

    // Webhooks (from IMS — M2M auth)
    'POST /webhooks/ims' => ['handler' => 'integration/ims/WebhookHandler', 'auth' => true, 'm2m' => true],

    // Settings
    'GET /settings/secrets/health' => ['handler' => 'settings/SecretsHealthHandler', 'auth' => true, 'permission' => 'nms.settings.read'],
    'GET /settings/redis/health'   => ['handler' => 'settings/RedisHealthHandler',   'auth' => true, 'permission' => 'nms.settings.read'],
];

// ─── Router ──────────────────────────────────────────────────────────────────

function matchRoute(string $method, string $uri, array $routes): ?array
{
    $key = "$method $uri";
    if (isset($routes[$key])) {
        return ['route' => $routes[$key], 'params' => []];
    }

    foreach ($routes as $pattern => $route) {
        [$routeMethod, $routePath] = explode(' ', $pattern, 2);
        if ($routeMethod !== $method) {
            continue;
        }

        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
        $regex = '@^' . $regex . '$@';
        if (preg_match($regex, $uri, $matches)) {
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['route' => $route, 'params' => $params];
        }
    }

    return null;
}

$match = matchRoute($method, $uri, $routes);

if ($match === null) {
    Response::error('Endpoint not found', 404);
}

$routeConfig = $match['route'];
$params      = $match['params'];
$request['params'] = $params;

// ─── Middleware stack ─────────────────────────────────────────────────────────

require_once __DIR__ . '/middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/RBACMiddleware.php';
require_once __DIR__ . '/middleware/IdempotencyMiddleware.php';
require_once __DIR__ . '/middleware/AuditMiddleware.php';

use NMS\Api\Middleware\RateLimitMiddleware;
use NMS\Api\Middleware\AuthMiddleware;
use NMS\Api\Middleware\RBACMiddleware;
use NMS\Api\Middleware\IdempotencyMiddleware;
use NMS\Api\Middleware\AuditMiddleware;

// Always rate limit
RateLimitMiddleware::handle($request);

// Auth required?
$jwtClaims = null;
if ($routeConfig['auth'] ?? true) {
    $jwtClaims = AuthMiddleware::handle($request);
    $request['user'] = $jwtClaims;
}

// RBAC check
if (isset($routeConfig['permission']) && $jwtClaims !== null) {
    RBACMiddleware::handle($jwtClaims, $routeConfig['permission']);
}

// Idempotency key check for idempotent routes
if ($routeConfig['idempotent'] ?? false) {
    $cached = IdempotencyMiddleware::handle($request);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }
}

// ─── Dispatch to handler ──────────────────────────────────────────────────────

$handlerPath = __DIR__ . '/handlers/' . $routeConfig['handler'] . '.php';
if (!file_exists($handlerPath)) {
    Response::error('Handler not implemented yet', 501);
}

require $handlerPath;

// Audit log for mutating requests
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) && $jwtClaims !== null) {
    AuditMiddleware::log($request, $jwtClaims, $routeConfig['handler']);
}
