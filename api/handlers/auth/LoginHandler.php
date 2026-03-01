<?php

declare(strict_types=1);

/**
 * POST /api/auth/login
 *
 * Validates credentials via IMS JWT (or shared key),
 * returns access + refresh tokens.
 */

use NMS\Core\Auth\JWTHelper;
use NMS\Core\Helpers\Response;
use NMS\Core\Helpers\Validator;
use NMS\Core\Models\Secrets\VaultSecretsManager;

try {
    $v = new Validator();
    $data = $v->validate($body, [
        'username' => 'required|string',
        'password' => 'required|string',
    ]);

    if ($v->fails()) {
        Response::unprocessable('Validation failed', $v->getErrors());
    }

    // Validate credentials against IMS
    // IMS is the source of truth for users. NMS calls IMS to authenticate,
    // then issues its own JWT with the permissions claim.
    $imsConfig = (require dirname(__DIR__, 3) . '/core/config/app.php')['ims'];
    $imsApiUrl = $imsConfig['api_url'] ?? '';

    if (empty($imsApiUrl)) {
        // Dev/test fallback — use shared key from Vault
        // In production, IMS validates credentials
        Response::error('IMS integration not configured', 503);
    }

    // Call IMS auth endpoint
    $ch = curl_init($imsApiUrl . '/api/auth/validate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'username' => $data['username'],
            'password' => $data['password'],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $imsResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        Response::unauthorized('Invalid credentials');
    }

    $imsUser = json_decode($imsResponse, true);
    if (!$imsUser || !isset($imsUser['user'])) {
        Response::unauthorized('Invalid credentials');
    }

    $user = $imsUser['user'];

    // Issue NMS tokens
    $secrets  = new VaultSecretsManager();
    $jwt      = new JWTHelper($secrets);

    $accessToken  = $jwt->generateAccessToken($user);
    $refreshToken = $jwt->generateRefreshToken($user);

    Response::json([
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => (require dirname(__DIR__, 3) . '/core/config/app.php')['jwt']['expiry'] ?? 900,
        'user' => [
            'id'          => $user['id'],
            'roles'       => $user['roles'] ?? [],
            'permissions' => $user['permissions'] ?? [],
        ],
    ]);

} catch (Response) {
    // Already sent
} catch (\Exception $e) {
    Response::error('Authentication failed: ' . $e->getMessage(), 500);
}
