<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\VPN;

use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Models\VPN\VpnUserManager;

/**
 * UsersCreateHandler
 *
 * POST /api/vpn/users — create VPN user (password hashed with Argon2id)
 */
class UsersCreateHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'POST') {
            Response::error('Method not allowed', 405);
            return;
        }

        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.vpn.write', $perms, true)) {
            Response::error('Forbidden: nms.vpn.write required', 403);
            return;
        }

        $body   = $request['body'] ?? [];
        $errors = Validator::validate($body, [
            'gateway_id' => 'required|string',
            'username'   => 'required|string',
            'password'   => 'required|string',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $userId  = $claims['sub'] ?? '000000000000000000000000';
        $manager = new VpnUserManager();

        $user = $manager->create($body, $userId);
        Response::json(['data' => $user], 201);
    }
}
