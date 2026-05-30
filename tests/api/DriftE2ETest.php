<?php

namespace Tests\API;

use PHPUnit\Framework\TestCase;

class DriftE2ETest extends TestCase
{
    protected $baseUrl = 'http://localhost:8000';
    protected $token;
    protected $deviceId;

    protected function setUp(): void
    {
        // Login
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ]);
        $this->token = $response['body']['token'] ?? null;
        $this->assertNotNull($this->token);

        // Get first device for testing
        $response = $this->request('GET', '/api/devices', [], $this->token);
        $devices = $response['body']['devices'] ?? [];
        $this->assertGreaterThan(0, count($devices));
        $this->deviceId = $devices[0]['_id'];
    }

    public function testDriftDetection()
    {
        // Scan device for drift
        $response = $this->request('POST', '/api/devices/' . $this->deviceId . '/drift/scan', [], $this->token);
        $this->assertEquals(200, $response['status']);

        // If drift found, we should have a result
        if (isset($response['body']['drift_id'])) {
            $driftId = $response['body']['drift_id'];

            // Get drift details
            $response = $this->request('GET', '/api/drift/' . $driftId, [], $this->token);
            $this->assertEquals(200, $response['status']);
            $drift = $response['body'];

            $this->assertEquals('open', $drift['status']);
            $this->assertArrayHasKey('diffs', $drift);
            $this->assertGreaterThan(0, count($drift['diffs']));

            return $driftId;
        }

        $this->markTestSkipped('No drift detected on test device');
    }

    /**
     * @depends testDriftDetection
     */
    public function testDriftResolution($driftId)
    {
        // Resolve as PULL (accept device state)
        $response = $this->request('POST', '/api/drift/' . $driftId . '/resolve', [
            'action' => 'pull'
        ], $this->token);
        $this->assertEquals(200, $response['status']);

        // Verify drift is resolved
        $response = $this->request('GET', '/api/drift/' . $driftId, [], $this->token);
        $drift = $response['body'];
        $this->assertEquals('resolved', $drift['status']);
    }

    public function testDriftList()
    {
        // Get all drifts
        $response = $this->request('GET', '/api/drift?limit=10', [], $this->token);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('drifts', $response['body']);
    }

    public function testDeviceDriftStatus()
    {
        // Get device drift status
        $response = $this->request('GET', '/api/devices/' . $this->deviceId . '/drift', [], $this->token);
        $this->assertEquals(200, $response['status']);

        $result = $response['body'];
        $this->assertArrayHasKey('drift', $result);
        $this->assertArrayHasKey('status', $result['drift']);
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
