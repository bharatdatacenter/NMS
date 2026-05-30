<?php

namespace Tests\API;

use PHPUnit\Framework\TestCase;

class FullE2ETest extends TestCase
{
    protected $baseUrl = 'http://localhost:8000';
    protected $token;

    protected function setUp(): void
    {
        // Login before each test
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ]);
        $this->token = $response['body']['token'] ?? null;
        $this->assertNotNull($this->token);
    }

    public function testIPAMAllocation()
    {
        // Get IPAM pools
        $response = $this->request('GET', '/api/ipam/pools', [], $this->token);
        $this->assertEquals(200, $response['status']);
        $pools = $response['body']['pools'] ?? [];
        $this->assertGreaterThan(0, count($pools));

        $pool = $pools[0];
        $poolId = $pool['_id'];

        // Allocate IP
        $response = $this->request('POST', '/api/ipam/pools/' . $poolId . '/allocate', [], $this->token);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('ip_address', $response['body']);

        $ip = $response['body']['ip_address'];

        // Verify allocation
        $response = $this->request('GET', '/api/ipam/pools/' . $poolId, [], $this->token);
        $allocations = $response['body']['allocations'] ?? [];
        $this->assertTrue(in_array($ip, array_column($allocations, 'ip_address')));
    }

    public function testDeviceDiscovery()
    {
        // Create device
        $deviceData = [
            'name' => 'test-router-01',
            'ip_address' => '192.168.1.1',
            'device_type' => 'router',
            'vendor' => 'mikrotik',
            'site_id' => 'site1'
        ];

        $response = $this->request('POST', '/api/devices', $deviceData, $this->token);
        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('_id', $response['body']);

        $deviceId = $response['body']['_id'];

        // Get device
        $response = $this->request('GET', '/api/devices/' . $deviceId, [], $this->token);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('test-router-01', $response['body']['name']);

        // Get device status
        $response = $this->request('GET', '/api/devices/' . $deviceId . '/status', [], $this->token);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('status', $response['body']);
    }

    protected function request($method, $endpoint, $data = [], $token = null)
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data) && in_array($method, ['POST', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $statusCode,
            'body' => json_decode($response, true)
        ];
    }
}
