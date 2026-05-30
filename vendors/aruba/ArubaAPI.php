<?php

declare(strict_types=1);

namespace NMS\Vendors\Aruba;

/**
 * ArubaAPI — raw HTTP client for Aruba CX REST API.
 *
 * API Base URL: https://{device_ip}/rest/v10.04
 * Authentication: Session-based (login → cookie → requests → logout)
 */
class ArubaAPI
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int    $timeout;

    /** Session cookie value (obtained after login) */
    private ?string $sessionCookie = null;

    public function __construct(string $host, string $username, string $password, int $timeout = 30)
    {
        $this->baseUrl  = "https://{$host}/rest/v10.04";
        $this->username = $username;
        $this->password = $password;
        $this->timeout  = $timeout;
    }

    // ─── Session Management ───────────────────────────────────────────────────

    /**
     * Login — POST /login with credentials, store session cookie.
     */
    public function login(): void
    {
        $url = $this->baseUrl . '/login';
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("curl_init failed for Aruba login URL: {$url}");
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'username' => $this->username,
                'password' => $this->password,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Aruba login request failed: {$error}");
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \RuntimeException("Aruba login HTTP {$httpCode}");
        }

        // Extract Set-Cookie header
        if (preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', (string)$response, $matches)) {
            $this->sessionCookie = trim($matches[1]);
        } else {
            throw new \RuntimeException('Aruba login succeeded but no session cookie returned');
        }
    }

    /**
     * Logout — POST /logout, clear session cookie.
     */
    public function logout(): void
    {
        if ($this->sessionCookie === null) {
            return;
        }

        try {
            $this->request('POST', $this->baseUrl . '/logout');
        } catch (\Throwable) {
            // Best-effort logout
        } finally {
            $this->sessionCookie = null;
        }
    }

    public function isLoggedIn(): bool
    {
        return $this->sessionCookie !== null;
    }

    // ─── API Endpoints ────────────────────────────────────────────────────────

    /** GET /system/vlans */
    public function getVLANs(): array
    {
        $body = $this->get('/system/vlans');
        return $body;
    }

    /** GET /system/interfaces */
    public function getInterfaces(): array
    {
        $body = $this->get('/system/interfaces');
        return $body;
    }

    /** GET /system/interfaces/{id} */
    public function getInterfaceDetail(string $ifName): array
    {
        return $this->get('/system/interfaces/' . urlencode($ifName));
    }

    /** GET /system/acls */
    public function getACLs(): array
    {
        $body = $this->get('/system/acls');
        return $body;
    }

    /** GET /system */
    public function getSystemInfo(): array
    {
        return $this->get('/system');
    }

    /** GET /system/arp */
    public function getARPTable(): array
    {
        try {
            return $this->get('/system/arp');
        } catch (\Exception) {
            return [];
        }
    }

    // ─── Raw HTTP ─────────────────────────────────────────────────────────────

    public function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $this->baseUrl . $path, $body);
    }

    public function delete(string $path): void
    {
        $this->request('DELETE', $this->baseUrl . $path);
    }

    // ─── cURL ─────────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): array
    {
        if ($this->sessionCookie === null && !str_ends_with($url, '/logout')) {
            // Auto-login on first call
            $this->login();
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("curl_init failed for URL: {$url}");
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($this->sessionCookie !== null) {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Aruba request failed: {$error}");
        }

        if ($httpCode === 204 || $httpCode === 0) {
            return [];
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Aruba HTTP {$httpCode}: {$response}");
        }

        if (empty($response)) {
            return [];
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Aruba JSON decode error: ' . json_last_error_msg());
        }

        return $decoded ?? [];
    }
}
