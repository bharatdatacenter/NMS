<?php

declare(strict_types=1);

/**
 * GET /api/auth/me
 *
 * Returns decoded JWT claims for the currently authenticated user.
 */

use NMS\Core\Helpers\Response;

$claims = $request['user'] ?? null;
if (!$claims) {
    Response::unauthorized('Not authenticated');
}

Response::json([
    'id'          => $claims->sub ?? null,
    'issuer'      => $claims->iss ?? null,
    'audience'    => $claims->aud ?? null,
    'issued_at'   => $claims->iat ?? null,
    'expires_at'  => $claims->exp ?? null,
    'token_type'  => $claims->type ?? null,
    'roles'       => (array)($claims->roles ?? []),
    'permissions' => (array)($claims->permissions ?? []),
]);
