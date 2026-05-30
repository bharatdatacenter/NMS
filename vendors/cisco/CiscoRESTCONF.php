<?php

declare(strict_types=1);

namespace NMS\Vendors\Cisco;

/**
 * CiscoRESTCONF — raw HTTP client for Cisco IOS-XE RESTCONF API.
 *
 * API Base URL: https://{device_ip}/restconf/data
 * Authentication: HTTP Basic Auth
 * Headers: Accept/Content-Type: application/yang-data+json
 */
class CiscoRESTCONF
{
    private string $baseUrl;
    private string $authHeader;
    private int    $timeout;

    public function __construct(string $host, string $username, string $password, int $timeout = 30)
    {
        $this->baseUrl    = "https://{$host}/restconf/data";
        $this->authHeader = 'Basic ' . base64_encode("{$username}:{$password}");
        $this->timeout    = $timeout;
    }

    // ─── Public API Accessors ─────────────────────────────────────────────────

    /** GET /restconf/data/ietf-interfaces:interfaces */
    public function getInterfaces(): array
    {
        $body = $this->get('/ietf-interfaces:interfaces');
        return $body['ietf-interfaces:interfaces']['interface'] ?? [];
    }

    /** GET /restconf/data/Cisco-IOS-XE-acl:acl */
    public function getACLs(): array
    {
        $body = $this->get('/Cisco-IOS-XE-acl:acl');
        return $body['Cisco-IOS-XE-acl:acl'] ?? [];
    }

    /** GET /restconf/data/Cisco-IOS-XE-native:native */
    public function getNativeConfig(): array
    {
        $body = $this->get('/Cisco-IOS-XE-native:native');
        return $body['Cisco-IOS-XE-native:native'] ?? [];
    }

    /** GET /restconf/data/Cisco-IOS-XE-arp-oper:arp-data */
    public function getARPTable(): array
    {
        try {
            $body = $this->get('/Cisco-IOS-XE-arp-oper:arp-data');
            return $body['Cisco-IOS-XE-arp-oper:arp-data']['arp-vrf']['arp-entry'] ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    /** GET /restconf/data/Cisco-IOS-XE-device-hardware-oper:device-hardware-data */
    public function getSystemInfo(): array
    {
        try {
            $body = $this->get('/Cisco-IOS-XE-device-hardware-oper:device-hardware-data');
            return $body['Cisco-IOS-XE-device-hardware-oper:device-hardware-data']['device-hardware'] ?? [];
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

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
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
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("curl_init failed for URL: {$url}");
        }

        $headers = [
            'Authorization: ' . $this->authHeader,
            'Accept: application/yang-data+json',
            'Content-Type: application/yang-data+json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,  // Common in lab environments
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
            throw new \RuntimeException("RESTCONF request failed: {$error}");
        }

        if ($httpCode === 204 || $httpCode === 0) {
            return [];
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("RESTCONF HTTP {$httpCode}: {$response}");
        }

        if (empty($response)) {
            return [];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('RESTCONF JSON decode error: ' . json_last_error_msg());
        }

        return $decoded ?? [];
    }
}
