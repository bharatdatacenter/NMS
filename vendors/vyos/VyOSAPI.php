<?php

declare(strict_types=1);

namespace NMS\Vendors\VyOS;

use NMS\Core\Helpers\RetryableException;
use RuntimeException;

/**
 * VyOSAPI — raw HTTP client for the VyOS REST API.
 *
 * Base URL: https://{host}
 * Auth:     API key in POST body as form data
 *
 * VyOS REST API endpoints:
 *   POST /configure  — set/delete configuration paths
 *   POST /retrieve   — get configuration values
 *   POST /show       — operational show commands
 *   POST /generate   — generate configs/keys
 *   POST /reset      — reset functions
 *
 * All requests are multipart/form-data with fields: key, data (JSON)
 */
class VyOSAPI
{
    private string $host;
    private string $apiKey;
    private bool $verifySsl;
    private int $timeoutSeconds;

    public function __construct(
        string $host,
        string $apiKey,
        bool $verifySsl = false,
        int $timeoutSeconds = 10
    ) {
        $this->host           = rtrim($host, '/');
        $this->apiKey         = $apiKey;
        $this->verifySsl      = $verifySsl;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Retrieve configuration at a given path.
     *
     * @param  string   $path  VyOS config path, e.g. "interfaces ethernet eth0"
     * @param  string   $op    "returnValue" | "returnValues" | "exists" | "listNodes"
     * @return array|string    Retrieved value(s)
     */
    public function retrieve(string $path, string $op = 'returnValues'): mixed
    {
        return $this->post('/retrieve', [
            'op'   => $op,
            'path' => $this->pathToArray($path),
        ]);
    }

    /**
     * Run an operational show command.
     *
     * @param  string $command  The show command path, e.g. "interfaces"
     */
    public function show(string $command): mixed
    {
        return $this->post('/show', [
            'op'   => 'show',
            'path' => $this->pathToArray($command),
        ]);
    }

    /**
     * Set a configuration value.
     *
     * @param  string $path   Config path
     * @param  string $value  Value to set (optional for leafless nodes)
     */
    public function set(string $path, string $value = ''): bool
    {
        $data = [
            'op'   => 'set',
            'path' => $this->pathToArray($path),
        ];
        if ($value !== '') {
            $data['value'] = $value;
        }
        $result = $this->post('/configure', $data);
        return ($result['success'] ?? false) === true || $result === true;
    }

    /**
     * Delete a configuration value.
     */
    public function delete(string $path): bool
    {
        $result = $this->post('/configure', [
            'op'   => 'delete',
            'path' => $this->pathToArray($path),
        ]);
        return ($result['success'] ?? false) === true || $result === true;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * POST to a VyOS endpoint with JSON data in form field.
     *
     * @throws RetryableException on transient failures
     * @throws RuntimeException   on permanent failures
     */
    private function post(string $endpoint, array $data): mixed
    {
        $url = "https://{$this->host}{$endpoint}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            // VyOS API uses multipart form data: key=apikey&data=json
            CURLOPT_POSTFIELDS     => [
                'key'  => $this->apiKey,
                'data' => json_encode($data),
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RetryableException("VyOS curl error: {$curlError}", $curlErrno);
        }

        if ($response === '' || $response === null) {
            return [];
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // VyOS show commands sometimes return plain text
            return ['output' => (string)$response];
        }

        // Transient failures
        if ($httpCode === 429 || ($httpCode >= 500 && $httpCode < 600)) {
            $message = (is_array($decoded) ? ($decoded['error'] ?? '') : '') ?: "HTTP {$httpCode}";
            throw new RetryableException("VyOS transient error: {$message}", $httpCode);
        }

        // Client errors
        if ($httpCode >= 400) {
            $message = (is_array($decoded) ? ($decoded['error'] ?? '') : '') ?: "HTTP {$httpCode}";
            throw new RuntimeException("VyOS API error: {$message}", $httpCode);
        }

        // VyOS wraps: {"success": true, "data": ..., "error": null}
        if (isset($decoded['success'])) {
            if (!$decoded['success']) {
                throw new RuntimeException("VyOS error: " . ($decoded['error'] ?? 'unknown'));
            }
            return $decoded['data'] ?? true;
        }

        return $decoded;
    }

    /**
     * Convert a space-separated path string to an array.
     * e.g. "protocols static route 10.0.0.0/8" → ["protocols", "static", "route", "10.0.0.0/8"]
     */
    private function pathToArray(string $path): array
    {
        return array_values(array_filter(explode(' ', trim($path))));
    }
}
