<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Auth\JWTHelper;
use NMS\Core\Models\Secrets\SecretsManagerInterface;

class JWTHelperTest extends TestCase
{
    private JWTHelper $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JWTHelper($this->makeFakeSecrets());
    }

    public function testGenerateAndValidateToken(): void
    {
        $token = $this->jwt->generate([
            'sub' => 'user-123',
            'aud' => 'nms',
            'exp' => time() + 900,
            'type'=> 'access',
        ]);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        $claims = $this->jwt->validate($token);
        $this->assertEquals('user-123', $claims->sub);
        $this->assertEquals('nms', $claims->aud);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->jwt->generate([
            'sub' => 'user-123',
            'aud' => 'nms',
            'exp' => time() - 1, // Already expired
            'type'=> 'access',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->jwt->validate($token);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $token = $this->jwt->generate([
            'sub' => 'user-123',
            'exp' => time() + 900,
        ]);

        // Tamper with payload
        $parts = explode('.', $token);
        $tamperedPayload = base64_encode(json_encode(['sub' => 'attacker', 'exp' => time() + 9999]));
        $tamperedToken   = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

        $this->expectException(\RuntimeException::class);
        $this->jwt->validate($tamperedToken);
    }

    public function testGenerateAccessToken(): void
    {
        $user = ['id' => 'u1', 'roles' => ['admin'], 'permissions' => ['nms.device.read']];
        $token = $this->jwt->generateAccessToken($user);
        $claims = $this->jwt->validate($token);

        $this->assertEquals('u1', $claims->sub);
        $this->assertEquals('access', $claims->type);
        $this->assertContains('nms.device.read', (array)$claims->permissions);
    }

    public function testGenerateRefreshToken(): void
    {
        $user = ['id' => 'u1', 'roles' => [], 'permissions' => []];
        $token = $this->jwt->generateRefreshToken($user);
        $claims = $this->jwt->validate($token);

        $this->assertEquals('nms-refresh', $claims->aud);
        $this->assertEquals('refresh', $claims->type);
    }

    public function testDualKeyRotation(): void
    {
        // Token signed with "old" key should be accepted during rotation window
        $oldSecrets = $this->makeFakeSecrets('old-signing-key-32bytes-padding!!');
        $newSecrets = $this->makeFakeSecrets('new-signing-key-32bytes-padding!!', 'old-signing-key-32bytes-padding!!');

        $oldJwt = new JWTHelper($oldSecrets, $this->testConfig());
        $newJwt = new JWTHelper($newSecrets, $this->testConfig());

        $tokenSignedByOldKey = $oldJwt->generate([
            'sub' => 'user-1',
            'exp' => time() + 900,
            'aud' => 'nms',
        ]);

        // New JWT helper (with old key as previous) should accept the old-signed token
        $claims = $newJwt->validate($tokenSignedByOldKey);
        $this->assertEquals('user-1', $claims->sub);
    }

    public function testGetRemainingTtl(): void
    {
        $exp = time() + 500;
        $token = $this->jwt->generate(['sub' => 'u1', 'exp' => $exp]);
        $ttl   = $this->jwt->getRemainingTtl($token);
        $this->assertGreaterThan(490, $ttl);
        $this->assertLessThanOrEqual(500, $ttl);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeFakeSecrets(
        string $signingKey = 'test-signing-key-32bytes-padding!',
        ?string $previousKey = null
    ): SecretsManagerInterface {
        return new class($signingKey, $previousKey) implements SecretsManagerInterface {
            public function __construct(
                private string $key,
                private ?string $prev
            ) {}

            public function get(string $path): string
            {
                if (str_ends_with($path, '_previous')) {
                    if ($this->prev === null) {
                        throw new \RuntimeException("No previous key");
                    }
                    return $this->prev;
                }
                return $this->key;
            }

            public function put(string $path, string $value): void {}
            public function delete(string $path): void {}
            public function exists(string $path): bool { return true; }
        };
    }

    private function testConfig(): array
    {
        return [
            'jwt' => [
                'expiry'         => 900,
                'refresh_expiry' => 604800,
                'issuer'         => 'nms',
                'audience'       => 'nms',
            ],
        ];
    }
}
