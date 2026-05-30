<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\VPN;

use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Models\VPN\VpnGatewayManager;

/**
 * GatewaysHandler
 *
 * GET  /api/vpn/gateways  — list VPN gateways
 * POST /api/vpn/gateways  — create VPN gateway (PSK stored in Vault)
 */
class GatewaysHandler
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
        $manager = new VpnGatewayManager($secrets);

        $filters = [];
        if (!empty($request['query']['device_id'])) {
            $filters['device_id'] = $request['query']['device_id'];
        }
        if (!empty($request['query']['gateway_type'])) {
            $filters['gateway_type'] = $request['query']['gateway_type'];
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
            'device_id'    => 'required|string',
            'name'         => 'required|string',
            'gateway_type' => 'required|in:ipsec,openvpn,wireguard,l2tp',
            'public_ip'    => 'required|ip',
            'psk'          => 'required|string',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $psk  = $body['psk'];
        unset($body['psk']);  // PSK goes to Vault, not MongoDB

        $secrets = new VaultSecretsManager();
        $manager = new VpnGatewayManager($secrets);

        $gateway = $manager->create($body, $psk);
        Response::json(['data' => $gateway], 201);
    }
}
