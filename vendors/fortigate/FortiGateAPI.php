<?php

declare(strict_types=1);

namespace NMS\Vendors\FortiGate;

use NMS\Core\Helpers\RetryableException;
use RuntimeException;

/**
 * FortiGateAPI — raw HTTP client for the Fortinet FortiGate REST API.
 *
 * Base URL: https://{host}/api/v2
 * Auth:     Bearer token in Authorization header
 *
 * Throws RetryableException on transient failures (timeout, 5xx, 429).
 * Throws RuntimeException on permanent failures (4xx).
 */
class FortiGateAPI
{
    private string $host;
    private string $token;
    private bool $verifySsl;
    private int $timeoutSeconds;

    public function __construct(
        string $host,
        string $token,
        bool $verifySsl = false,
        int $timeoutSeconds = 10
    ) {
        $this->host           = rtrim($host, '/');
        $this->token          = $token;
        $this->verifySsl      = $verifySsl;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * GET /api/v2{endpoint}
     *
     * @param array<string,string> $params Optional query parameters
     */
    public function get(string $endpoint, array $params = []): array
    {
        $url = "https://{$this->host}/api/v2{$endpoint}";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    /**
     * POST /api/v2{endpoint}
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', "https://{$this->host}/api/v2{$endpoint}", $data);
    }

    /**
     * PUT /api/v2{endpoint}
     */
    public function put(string $endpoint, array $data): array
    {
        return $this->request('PUT', "https://{$this->host}/api/v2{$endpoint}", $data);
    }

    /**
     * DELETE /api/v2{endpoint}
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', "https://{$this->host}/api/v2{$endpoint}");
    }

    /**
     * GET /api/v2/monitor{endpoint} — monitoring (read-only, real-time data)
     */
    public function monitor(string $endpoint, array $params = []): array
    {
        $url = "https://{$this->host}/api/v2/monitor{$endpoint}";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
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
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
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

        if ($response === false) {
            throw new RetryableException("FortiGate curl error: {$curlError}", $curlErrno);
        }

        if ($response === '' || $response === null) {
            return [];
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON response from FortiGate: ' . substr((string)$response, 0, 200));
        }

        // FortiGate wraps results: {"http_status": 200, "results": [...]}
        $status = $decoded['http_status'] ?? $httpCode;

        // Transient failures
        if ($status === 429 || ($status >= 500 && $status < 600)) {
            $message = $decoded['http_method'] ?? $decoded['status'] ?? "HTTP {$status}";
            throw new RetryableException("FortiGate transient error: {$message}", (int)$status);
        }

        // Client errors
        if ($status >= 400) {
            $message = $decoded['cli_error'] ?? $decoded['status'] ?? "HTTP {$status}";
            throw new RuntimeException("FortiGate API error: {$message}", (int)$status);
        }

        // Return the results array if present, otherwise the full response
        return $decoded['results'] ?? $decoded;
    }
}
