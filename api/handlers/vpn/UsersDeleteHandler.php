<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\VPN;

use NMS\Core\Helpers\Response;
use NMS\Core\Models\VPN\VpnUserManager;

/**
 * UsersDeleteHandler
 *
 * DELETE /api/vpn/users/{id} — delete VPN user
 */
class UsersDeleteHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'DELETE') {
            Response::error('Method not allowed', 405);
            return;
        }

        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.vpn.write', $perms, true)) {
            Response::error('Forbidden: nms.vpn.write required', 403);
            return;
        }

        $id = $request['params']['id'] ?? '';
        if (empty($id)) {
            Response::error('User ID required', 400);
            return;
        }

        $manager = new VpnUserManager();
        $deleted = $manager->delete($id);

        if (!$deleted) {
            Response::error('VPN user not found', 404);
            return;
        }

        Response::json(['message' => 'VPN user deleted'], 200);
    }
}
