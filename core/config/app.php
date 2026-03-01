<?php

declare(strict_types=1);

/**
 * Application configuration — loads from .env file
 */

// Load .env if it exists
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

return [
    'env'   => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'url'   => getenv('APP_URL') ?: 'http://localhost',
    'name'  => 'NMS',

    'jwt' => [
        'expiry'          => (int)(getenv('JWT_EXPIRY') ?: 900),
        'refresh_expiry'  => (int)(getenv('JWT_REFRESH_EXPIRY') ?: 604800),
        'issuer'          => getenv('JWT_ISSUER') ?: 'nms',
        'audience'        => getenv('JWT_AUDIENCE') ?: 'nms',
    ],

    'rate_limit' => [
        'requests' => (int)(getenv('RATE_LIMIT_REQUESTS') ?: 100),
        'window'   => (int)(getenv('RATE_LIMIT_WINDOW') ?: 60),
    ],

    'ims' => [
        'api_url'       => getenv('IMS_API_URL') ?: '',
        'm2m_client_id' => getenv('IMS_M2M_CLIENT_ID') ?: '',
        'm2m_vault_path'=> getenv('IMS_M2M_VAULT_PATH') ?: 'nms/ims/m2m_secret',
    ],

    'zabbix' => [
        'api_url'    => getenv('ZABBIX_API_URL') ?: '',
        'vault_path' => getenv('ZABBIX_VAULT_PATH') ?: 'nms/zabbix/api_token',
    ],
];
