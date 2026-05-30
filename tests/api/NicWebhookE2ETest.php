<?php

namespace Tests\API;

use PHPUnit\Framework\TestCase;

class NicWebhookE2ETest extends TestCase
{
    protected $baseUrl = 'http://localhost:8000';
    protected $token;
    protected $serverId;

    protected function setUp(): void
    {
        // Login
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ]);
        $this->token = $response['body']['token'] ?? null;
        $this->assertNotNull($this->token);
    }

    public function testNicWebhookProcessing()
    {
        // Create a test server/NIC record
        $nicData = [
            'server_id' => 'server-' . uniqid(),
            'nic_index' => 1,
            'mac_address' => '00:11:22:33:44:55',
            'vlan_id' => 100,
            'switch_port' => 'ge-0/0/1',
            'switch_id' => 'switch-1'
        ];

        $response = $this->request('POST', '/api/nics', $nicData, $this->token);
        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('_id', $response['body']);

        $nicId = $response['body']['_id'];
        $this->serverId = $nicData['server_id'];

        // Simulate webhook from IMS (server.nic_change event)
        $webhookPayload = [
            'event' => 'server.nic_change',
            'server_id' => $this->serverId,
            'nics' => [
                [
                    'index' => 1,
                    'mac_address' => '00:11:22:33:44:55',
                    'status' => 'connected',
                    'speed' => '1000Mbps',
                    'duplex' => 'full'
                ]
            ]
        ];

        // This would be called from IMS webhook handler
        // For testing, we simulate by calling the NIC manager directly
        $response = $this->request('POST', '/api/nics/webhook/ims-change', $webhookPayload, $this->token);
        $this->assertEquals(200, $response['status']);

        // Verify NIC record was updated
        $response = $this->request('GET', '/api/nics/' . $nicId, [], $this->token);
        $this->assertEquals(200, $response['status']);
        $nic = $response['body'];

        // Verify fields are set
        $this->assertArrayHasKey('server_id', $nic);
        $this->assertEquals($this->serverId, $nic['server_id']);
    }

    public function testServerNICQuery()
    {
        // Get all NICs for a server
        $response = $this->request('GET', '/api/nics/server/' . $this->serverId, [], $this->token);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('nics', $response['body']);
    }

    public function testNICConnectivity()
    {
        if (!$this->serverId) {
            $this->markTestSkipped('No server ID available');
        }

        // Get NIC details with connectivity info
        $response = $this->request('GET', '/api/nics/server/' . $this->serverId, [], $this->token);
        $this->assertEquals(200, $response['status']);

        $nics = $response['body']['nics'] ?? [];
        foreach ($nics as $nic) {
            $this->assertArrayHasKey('server_id', $nic);
            $this->assertArrayHasKey('nic_index', $nic);
            // Verify connectivity fields
            if (isset($nic['switch_port'])) {
                $this->assertNotEmpty($nic['switch_port']);
            }
        }
    }

    public function testNICIPAssignments()
    {
        if (!$this->serverId) {
            $this->markTestSkipped('No server ID available');
        }

        // Get NICs with IP assignment info
        $response = $this->request('GET', '/api/nics/server/' . $this->serverId, [], $this->token);
        $this->assertEquals(200, $response['status']);

        $nics = $response['body']['nics'] ?? [];
        foreach ($nics as $nic) {
            // NICs may have IPv4 and/or IPv6 assignments
            if (isset($nic['ip_assignments'])) {
                foreach ($nic['ip_assignments'] as $assignment) {
                    $this->assertArrayHasKey('ip_version', $assignment);
                    $this->assertArrayHasKey('ip_address', $assignment);
                    $this->assertTrue(in_array($assignment['ip_version'], ['ipv4', 'ipv6']));
                }
            }
        }
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
