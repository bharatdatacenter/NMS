<?php

declare(strict_types=1);

/**
 * POST /api/auth/m2m/token
 *
 * Issues an M2M token for NMS→IMS communication.
 * Requires nms.settings.write permission (admin only).
 */

use NMS\Core\Auth\JWTHelper;
use NMS\Core\Auth\M2MTokenHelper;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Models\Secrets\VaultSecretsManager;

try {
    $v = new Validator();
    $data = $v->validate($body, [
        'audience'    => 'required|string|enum:ims-m2m,nms-m2m',
        'permissions' => 'array',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    $secrets  = new VaultSecretsManager();
    $jwt      = new JWTHelper($secrets);
    $m2m      = new M2MTokenHelper($jwt);

    $audience = $data['audience'];
    $token    = match ($audience) {
        'ims-m2m' => $m2m->issueForIms(),
        'nms-m2m' => $m2m->issueForNms($data['permissions'] ?? []),
    };

    Response::json([
        'token'      => $token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'audience'   => $audience,
    ]);

} catch (\Exception $e) {
    Response::error('Token issuance failed: ' . $e->getMessage(), 500);
}
