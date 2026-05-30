<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\VPN;

use NMS\Core\Helpers\Response;
use NMS\Core\Models\VPN\VpnUserManager;

/**
 * UsersListHandler
 *
 * GET /api/vpn/users — list VPN users (password_hash never returned)
 */
class UsersListHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'GET') {
            Response::error('Method not allowed', 405);
            return;
        }

        $manager = new VpnUserManager();
        $filters = [];

        if (!empty($request['query']['gateway_id'])) {
            $filters['gateway_id'] = $request['query']['gateway_id'];
        }
        if (isset($request['query']['enabled'])) {
            $filters['enabled'] = filter_var($request['query']['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($request['query']['username'])) {
            $filters['username'] = $request['query']['username'];
        }

        $page    = max(1, (int)($request['query']['page'] ?? 1));
        $perPage = min(100, max(10, (int)($request['query']['per_page'] ?? 50)));

        $result = $manager->list($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $page, $perPage);
    }
}
