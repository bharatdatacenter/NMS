<?php

declare(strict_types=1);

namespace NMS\Vendors\MikroTik;

use NMS\Core\Helpers\RetryableException;
use RuntimeException;

/**
 * MikroTikAPI — raw HTTP client for the MikroTik RouterOS REST API.
 *
 * Base URL: https://{host}/rest
 * Auth:     HTTP Basic (username:password)
 *
 * All methods throw RetryableException on transient failures (timeout, 5xx, 429)
 * and RuntimeException on hard failures (4xx, invalid JSON).
 */
class MikroTikAPI
{
    private string $host;
    private string $username;
    private string $password;
    private bool $verifySsl;
    private int $timeoutSeconds;

    public function __construct(
        string $host,
        string $username,
        string $password,
        bool $verifySsl = false,
        int $timeoutSeconds = 10
    ) {
        $this->host           = rtrim($host, '/');
        $this->username       = $username;
        $this->password       = $password;
        $this->verifySsl      = $verifySsl;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * GET /rest{endpoint}
     *
     * @param array<string,string> $query Optional query parameters
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = "https://{$this->host}/rest{$endpoint}";
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    /**
     * PUT /rest{endpoint} — MikroTik uses PUT for create operations
     */
    public function put(string $endpoint, array $data): array
    {
        return $this->request('PUT', "https://{$this->host}/rest{$endpoint}", $data);
    }

    /**
     * PATCH /rest{endpoint} — MikroTik uses PATCH for updates
     */
    public function patch(string $endpoint, array $data): array
    {
        return $this->request('PATCH', "https://{$this->host}/rest{$endpoint}", $data);
    }

    /**
     * DELETE /rest{endpoint}
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', "https://{$this->host}/rest{$endpoint}");
    }

    /**
     * POST /rest{endpoint} — for commands/actions
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', "https://{$this->host}/rest{$endpoint}", $data);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * @throws RetryableException on transient failures
     * @throws RuntimeException   on permanent failures
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // cURL transport error → retryable
        if ($response === false) {
            throw new RetryableException("MikroTik curl error: {$curlError}", $curlErrno);
        }

        // Empty response is valid for DELETE
        if ($response === '' || $response === null) {
            return [];
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON response from MikroTik: ' . substr((string)$response, 0, 200));
        }

        // Transient failures
        if ($httpCode === 429 || ($httpCode >= 500 && $httpCode < 600)) {
            $message = (is_array($decoded) ? ($decoded['message'] ?? $decoded['detail'] ?? '') : '') ?: "HTTP {$httpCode}";
            throw new RetryableException("MikroTik transient error: {$message}", $httpCode);
        }

        // Client errors — not retryable
        if ($httpCode >= 400) {
            $message = (is_array($decoded) ? ($decoded['message'] ?? $decoded['detail'] ?? '') : '') ?: "HTTP {$httpCode}";
            throw new RuntimeException("MikroTik API error: {$message}", $httpCode);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
