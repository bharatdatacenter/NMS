<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\VPN;

use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Models\VPN\VpnTunnelManager;

/**
 * TunnelsHandler
 *
 * GET  /api/vpn/tunnels  — list VPN tunnels
 * POST /api/vpn/tunnels  — create VPN tunnel (PSK stored in Vault)
 */
class TunnelsHandler
{
    public function handle(array $request): void
    {
        match ($request['method']) {
            'GET'  => $this->list($request),
            'POST' => $this->create($request),
            default => Response::error('Method not allowed', 405),
        };
    }

    private function list(array $request): void
    {
        $secrets = new VaultSecretsManager();
        $manager = new VpnTunnelManager($secrets);

        $filters = [];
        if (!empty($request['query']['local_gateway_id'])) {
            $filters['local_gateway_id'] = $request['query']['local_gateway_id'];
        }
        if (!empty($request['query']['status'])) {
            $filters['status'] = $request['query']['status'];
        }
        if (isset($request['query']['enabled'])) {
            $filters['enabled'] = filter_var($request['query']['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        $page    = max(1, (int)($request['query']['page'] ?? 1));
        $perPage = min(100, max(10, (int)($request['query']['per_page'] ?? 50)));

        $result = $manager->list($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $page, $perPage);
    }

    private function create(array $request): void
    {
        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.vpn.write', $perms, true)) {
            Response::error('Forbidden: nms.vpn.write required', 403);
            return;
        }

        $body = $request['body'] ?? [];
        $errors = Validator::validate($body, [
            'name'             => 'required|string',
            'local_gateway_id' => 'required|string',
            'remote_gateway_ip' => 'required|ip',
            'psk'              => 'required|string',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $psk = $body['psk'];
        unset($body['psk']);

        $secrets = new VaultSecretsManager();
        $manager = new VpnTunnelManager($secrets);

        $tunnel = $manager->create($body, $psk);
        Response::json(['data' => $tunnel], 201);
    }
}
