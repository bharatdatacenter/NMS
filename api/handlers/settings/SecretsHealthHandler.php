<?php

declare(strict_types=1);

namespace NMS\Api\Handlers\Settings;

use NMS\Core\Helpers\Response;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;

/**
 * SecretsHealthHandler
 *
 * GET /api/settings/secrets/health — check Vault connectivity and fallback status
 */
class SecretsHealthHandler
{
    public function handle(array $request): void
    {
        if ($request['method'] !== 'GET') {
            Response::error('Method not allowed', 405);
            return;
        }

        $claims = $request['jwt_claims'] ?? [];
        $perms  = $claims['permissions'] ?? [];
        if (!in_array('nms.settings.read', $perms, true)) {
            Response::error('Forbidden: nms.settings.read required', 403);
            return;
        }

        $health = [
            'vault'    => $this->checkVault(),
            'fallback' => $this->checkFallback(),
            'checked_at' => date('c'),
        ];

        $allHealthy = $health['vault']['healthy'] || $health['fallback']['healthy'];
        $status     = $allHealthy ? 200 : 503;

        Response::json(['data' => $health], $status);
    }

    private function checkVault(): array
    {
        $config = require dirname(__DIR__, 4) . '/core/config/vault.php';

        $check = [
            'provider' => 'hashicorp_vault',
            'address'  => $config['address'] ?? null,
            'healthy'  => false,
            'error'    => null,
            'latency_ms' => null,
        ];

        $start = microtime(true);
        try {
            $vault = new VaultSecretsManager();
            // Health probe — read the sys/health endpoint
            $ch = curl_init(($config['address'] ?? '') . '/v1/sys/health');
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => ['X-Vault-Token: ' . ($config['token'] ?? '')],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 || $httpCode === 429 || $httpCode === 472 || $httpCode === 473) {
                $check['healthy'] = true;
                $body = json_decode((string)$response, true) ?? [];
                $check['initialized'] = $body['initialized'] ?? null;
                $check['sealed']      = $body['sealed'] ?? null;
                $check['version']     = $body['version'] ?? null;
            } else {
                $check['error'] = "Vault returned HTTP {$httpCode}";
            }
        } catch (\Throwable $e) {
            $check['error'] = $e->getMessage();
        }

        $check['latency_ms'] = (int)((microtime(true) - $start) * 1000);
        return $check;
    }

    private function checkFallback(): array
    {
        $check = [
            'provider' => 'app_encrypted',
            'healthy'  => false,
            'error'    => null,
        ];

        try {
            $key = getenv('NMS_ENCRYPTION_KEY');
            if (empty($key)) {
                $check['error'] = 'NMS_ENCRYPTION_KEY environment variable not set';
                return $check;
            }

            // Try a round-trip encrypt/decrypt
            $fallback = new AppEncryptedSecretsManager();
            $testVal  = 'health-check-' . time();
            $fallback->put('nms/health-check/test', $testVal);
            $retrieved = $fallback->get('nms/health-check/test');

            $check['healthy'] = $retrieved === $testVal;
            if (!$check['healthy']) {
                $check['error'] = 'Encrypt/decrypt round-trip failed';
            }
        } catch (\Throwable $e) {
            $check['error'] = $e->getMessage();
        }

        return $check;
    }
}
