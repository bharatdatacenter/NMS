<?php

declare(strict_types=1);

return [
    'addr'  => getenv('VAULT_ADDR') ?: 'http://127.0.0.1:8200',
    'token' => getenv('VAULT_TOKEN') ?: '',
    'mount' => getenv('VAULT_MOUNT') ?: 'secret',

    'paths' => [
        'jwt_secret'         => getenv('VAULT_PATH_JWT_SECRET') ?: 'nms/jwt/signing_key',
        'jwt_refresh_secret' => getenv('VAULT_PATH_JWT_REFRESH_SECRET') ?: 'nms/jwt/refresh_key',
        'device_creds'       => getenv('VAULT_PATH_DEVICE_CREDS') ?: 'nms/devices',
        'vpn_psk'            => getenv('VAULT_PATH_VPN_PSK') ?: 'nms/vpn/psk',
    ],

    // Fallback: app-layer AES-256-GCM encryption when Vault unavailable (temporary)
    'fallback_key_env_var' => getenv('APP_ENCRYPTION_KEY_ENV_VAR') ?: 'NMS_ENCRYPTION_KEY',

    'timeout' => 5,
];
