<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Core\Auth\TokenBlocklist;

class TokenBlocklistTest extends TestCase
{
    private TokenBlocklist $blocklist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushRedis();
        $this->blocklist = new TokenBlocklist(self::getRedis());
    }

    public function testRevokeAndIsRevoked(): void
    {
        $jti = 'test-jti-' . uniqid();
        $this->assertFalse($this->blocklist->isRevoked($jti));

        $this->blocklist->revoke($jti, 900);
        $this->assertTrue($this->blocklist->isRevoked($jti));
    }

    public function testZeroTtlNotStored(): void
    {
        $jti = 'zero-ttl-' . uniqid();
        $this->blocklist->revoke($jti, 0);
        $this->assertFalse($this->blocklist->isRevoked($jti));
    }

    public function testNegativeTtlNotStored(): void
    {
        $jti = 'neg-ttl-' . uniqid();
        $this->blocklist->revoke($jti, -100);
        $this->assertFalse($this->blocklist->isRevoked($jti));
    }

    public function testBulkRevocationForUser(): void
    {
        $userId = 'user-bulk-' . uniqid();
        $iatBefore = time() - 10;
        $iatAfter  = time() + 10;

        $this->blocklist->revokeAllForUser($userId);

        // Token issued before the bulk revocation timestamp should fail
        $this->assertFalse($this->blocklist->isIssuedAfterBulkRevocation($userId, $iatBefore));

        // Token issued after the timestamp should pass
        $this->assertTrue($this->blocklist->isIssuedAfterBulkRevocation($userId, $iatAfter));
    }

    public function testGetTokensValidAfterReturnsZeroIfNotSet(): void
    {
        $userId = 'never-revoked-' . uniqid();
        $this->assertEquals(0, $this->blocklist->getTokensValidAfter($userId));
    }

    public function testIsTokenValid(): void
    {
        $jti    = 'valid-jti-' . uniqid();
        $userId = 'user-' . uniqid();
        $iat    = time();

        // Should be valid initially
        $this->assertTrue($this->blocklist->isTokenValid($jti, $userId, $iat));

        // Revoke jti → invalid
        $this->blocklist->revoke($jti, 900);
        $this->assertFalse($this->blocklist->isTokenValid($jti, $userId, $iat));
    }

    public function testIsTokenValidAfterBulkRevoke(): void
    {
        $jti    = 'bulk-check-jti-' . uniqid();
        $userId = 'user-bulk-check-' . uniqid();
        $iatOld = time() - 100;

        $this->blocklist->revokeAllForUser($userId);

        // Old token (iat before bulk revoke timestamp) → invalid
        $this->assertFalse($this->blocklist->isTokenValid($jti, $userId, $iatOld));
    }
}
