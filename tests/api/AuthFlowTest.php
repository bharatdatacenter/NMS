<?php

namespace Tests\API;

use PHPUnit\Framework\TestCase;

class AuthFlowTest extends TestCase
{
    protected $baseUrl = 'http://localhost:8000';
    protected $testUser = [
        'email' => 'test@example.com',
        'password' => 'password123'
    ];

    public function testLoginFlow()
    {
        // Login
        $response = $this->post('/api/auth/login', $this->testUser);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('token', $response['body']);

        $token = $response['body']['token'];
        $this->assertNotEmpty($token);

        return $token;
    }

    /**
     * @depends testLoginFlow
     */
    public function testAccessProtectedEndpoint($token)
    {
        // Get current user
        $response = $this->get('/api/auth/me', $token);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('test@example.com', $response['body']['email']);
    }

    /**
     * @depends testLoginFlow
     */
    public function testTokenRefresh($token)
    {
        // Refresh token
        $response = $this->post('/api/auth/refresh', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('token', $response['body']);

        $newToken = $response['body']['token'];
        $this->assertNotEmpty($newToken);
        $this->assertNotEquals($token, $newToken);

        return $newToken;
    }

    /**
     * @depends testTokenRefresh
     */
    public function testLogout($token)
    {
        // Logout
        $response = $this->post('/api/auth/logout', [], $token);
        $this->assertEquals(200, $response['status']);

        // Verify token is revoked
        $response = $this->get('/api/auth/me', $token);
        $this->assertEquals(401, $response['status']);
    }

    public function testInvalidCredentials()
    {
        $response = $this->post('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword'
        ]);
        $this->assertEquals(401, $response['status']);
    }

    public function testMissingToken()
    {
        $response = $this->get('/api/auth/me', null);
        $this->assertEquals(401, $response['status']);
    }

    protected function post($endpoint, $data, $token = null)
    {
        return $this->request('POST', $endpoint, $data, $token);
    }

    protected function get($endpoint, $token = null)
    {
        return $this->request('GET', $endpoint, [], $token);
    }

    protected function request($method, $endpoint, $data, $token = null)
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($token) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        if (!empty($data)) {
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
