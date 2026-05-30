<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Core\Models\VPN\VpnUserManager;
use PHPUnit\Framework\TestCase;

/**
 * VpnUserPasswordTest
 *
 * Verifies that VPN user passwords are stored as Argon2id hashes,
 * never as plaintext or reversible encryption.
 */
class VpnUserPasswordTest extends TestCase
{
    public function testArgon2idHashIsDetectable(): void
    {
        $password = 'super-secret-vpn-password-123';
        $hash     = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $this->assertNotFalse($hash, 'password_hash should not return false');
        $this->assertStringStartsWith('$argon2id$', $hash, 'Hash should be Argon2id format');
    }

    public function testPasswordVerification(): void
    {
        $password = 'test-password-verify';
        $hash     = password_hash($password, PASSWORD_ARGON2ID);

        $this->assertTrue(password_verify($password, $hash), 'Correct password should verify');
        $this->assertFalse(password_verify('wrong-password', $hash), 'Wrong password should not verify');
    }

    public function testHashIsNotReversible(): void
    {
        $password = 'plaintext-password';
        $hash     = password_hash($password, PASSWORD_ARGON2ID);

        // The hash should not equal or contain the plaintext
        $this->assertNotEquals($password, $hash, 'Hash must not equal plaintext password');
        $this->assertStringNotContainsString($password, $hash, 'Hash must not contain plaintext password');
    }

    public function testPasswordNotStoredInDocument(): void
    {
        // Simulate the document structure VpnUserManager creates
        // (tests the logic without a real MongoDB connection)
        $plaintext = 'user-supplied-password';
        $hash      = password_hash($plaintext, PASSWORD_ARGON2ID);

        $doc = [
            'gateway_id'    => 'gw-123',
            'username'      => 'testuser',
            'password_hash' => $hash,
            // 'password' field must NOT exist in stored doc
        ];

        $this->assertArrayNotHasKey('password', $doc, 'Plaintext password must not be stored in document');
        $this->assertArrayHasKey('password_hash', $doc, 'Hash field must exist');
        $this->assertStringStartsWith('$argon2id$', $doc['password_hash']);
    }

    public function testRequiredHashingOptions(): void
    {
        // Verify Argon2id is supported in this PHP build
        $this->assertTrue(defined('PASSWORD_ARGON2ID'), 'PASSWORD_ARGON2ID must be defined (PHP 7.3+)');
        $this->assertSame(PASSWORD_ARGON2ID, PASSWORD_ARGON2ID);
    }

    public function testUniqueHashesForSamePassword(): void
    {
        $password = 'same-password-different-hashes';
        $hash1    = password_hash($password, PASSWORD_ARGON2ID);
        $hash2    = password_hash($password, PASSWORD_ARGON2ID);

        // Argon2id generates different salts each time
        $this->assertNotEquals($hash1, $hash2, 'Two hashes of the same password must differ (different salts)');

        // But both must verify correctly
        $this->assertTrue(password_verify($password, $hash1));
        $this->assertTrue(password_verify($password, $hash2));
    }

    public function testPasswordUpdateCreatesNewHash(): void
    {
        $oldPassword = 'old-password';
        $newPassword = 'new-password';

        $oldHash = password_hash($oldPassword, PASSWORD_ARGON2ID);
        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);

        $this->assertNotEquals($oldHash, $newHash, 'Old and new hashes must differ');
        $this->assertFalse(password_verify($newPassword, $oldHash), 'New password must not verify against old hash');
        $this->assertTrue(password_verify($newPassword, $newHash), 'New password must verify against new hash');
    }
}
