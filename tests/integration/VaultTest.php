<?php

declare(strict_types=1);

namespace NMS\Tests\Integration;

use NMS\Tests\TestCase;
use NMS\Core\Models\Secrets\VaultSecretsManager;
use NMS\Core\Models\Secrets\AppEncryptedSecretsManager;
use NMS\Core\Models\Secrets\SecretsManagerInterface;

/**
 * @group integration
 * Tests Vault if available, otherwise falls back to AppEncryptedSecretsManager.
 */
class VaultTest extends TestCase
{
    private SecretsManagerInterface $secrets;
    private bool $usingVault = false;

    protected function setUp(): void
    {
        parent::setUp();

        $vaultAddr = getenv('VAULT_ADDR') ?: 'http://127.0.0.1:8200';
        $vaultToken = getenv('VAULT_TOKEN') ?: '';

        // Try Vault first
        if ($vaultToken && $this->isVaultReachable($vaultAddr)) {
            $this->secrets    = new VaultSecretsManager();
            $this->usingVault = true;
        } else {
            // Fallback to AppEncryptedSecretsManager
            $testKey = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits
            putenv("NMS_ENCRYPTION_KEY={$testKey}");
            $this->secrets = new AppEncryptedSecretsManager([
                'fallback_key_env_var' => 'NMS_ENCRYPTION_KEY',
            ]);
        }
    }

    public function testStoreAndRetrieve(): void
    {
        $path  = 'nms/test/credential-' . uniqid();
        $value = 'super-secret-value-' . uniqid();

        $this->secrets->put($path, $value);
        $retrieved = $this->secrets->get($path);

        $this->assertEquals($value, $retrieved);

        // Clean up
        $this->secrets->delete($path);
    }

    public function testMissingPathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->secrets->get('nms/nonexistent/path-' . uniqid());
    }

    public function testExistsCheck(): void
    {
        $path  = 'nms/test/exists-' . uniqid();
        $value = 'test-value';

        $this->assertFalse($this->secrets->exists($path));

        $this->secrets->put($path, $value);
        $this->assertTrue($this->secrets->exists($path));

        $this->secrets->delete($path);
        $this->assertFalse($this->secrets->exists($path));
    }

    public function testRoundTrip(): void
    {
        $path  = 'nms/test/roundtrip-' . uniqid();
        $value = 'complex-value-' . bin2hex(random_bytes(16));

        $this->secrets->put($path, $value);
        $this->assertEquals($value, $this->secrets->get($path));

        // Update
        $newValue = 'updated-' . $value;
        $this->secrets->put($path, $newValue);
        $this->assertEquals($newValue, $this->secrets->get($path));

        $this->secrets->delete($path);
    }

    public function testBackendUsed(): void
    {
        $backendType = $this->usingVault ? 'VaultSecretsManager' : 'AppEncryptedSecretsManager';
        $this->addToAssertionCount(1);
        fwrite(STDERR, "\n[VaultTest] Using backend: {$backendType}\n");
    }

    private function isVaultReachable(string $addr): bool
    {
        $ch = curl_init($addr . '/v1/sys/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return in_array($code, [200, 429, 472, 473]); // Vault health codes
    }
}
