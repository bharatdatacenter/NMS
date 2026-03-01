<?php

declare(strict_types=1);

namespace NMS\Core\Models\Secrets;

use RuntimeException;

/**
 * HashiCorp Vault HTTP API implementation of SecretsManagerInterface.
 * Uses KV v2 secrets engine.
 */
class VaultSecretsManager implements SecretsManagerInterface
{
    private string $addr;
    private string $token;
    private string $mount;
    private int $timeout;

    public function __construct(?array $config = null)
    {
        $config ??= require dirname(__DIR__, 3) . '/core/config/vault.php';
        $this->addr   = rtrim($config['addr'], '/');
        $this->token  = $config['token'];
        $this->mount  = $config['mount'];
        $this->timeout = $config['timeout'] ?? 5;
    }

    public function get(string $path): string
    {
        $url = "{$this->addr}/v1/{$this->mount}/data/{$path}";
        $response = $this->request('GET', $url);

        $value = $response['data']['data']['value'] ?? null;
        if ($value === null) {
            throw new RuntimeException("Secret at path '{$path}' has no 'value' field");
        }
        return (string)$value;
    }

    public function put(string $path, string $value): void
    {
        $url = "{$this->addr}/v1/{$this->mount}/data/{$path}";
        $this->request('POST', $url, ['data' => ['value' => $value]]);
    }

    public function delete(string $path): void
    {
        $url = "{$this->addr}/v1/{$this->mount}/metadata/{$path}";
        $this->request('DELETE', $url);
    }

    public function exists(string $path): bool
    {
        try {
            $this->get($path);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'X-Vault-Token: ' . $this->token,
                'Content-Type: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("Vault connection error: {$error}");
        }

        if ($method === 'DELETE' && $httpCode === 204) {
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errBody = json_decode($raw ?: '{}', true);
            $errMsg  = implode(', ', $errBody['errors'] ?? ['HTTP ' . $httpCode]);
            throw new RuntimeException("Vault error ({$httpCode}): {$errMsg}");
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Vault returned invalid JSON");
        }
        return $decoded ?? [];
    }
}
