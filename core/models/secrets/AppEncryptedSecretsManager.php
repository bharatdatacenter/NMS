<?php

declare(strict_types=1);

namespace NMS\Core\Models\Secrets;

use RuntimeException;

/**
 * AES-256-GCM fallback secrets manager.
 * Stores encrypted secrets in a local JSON file.
 * Key comes from an OS keyring environment variable — never from .env.
 *
 * This is a temporary fallback when Vault is not available.
 */
class AppEncryptedSecretsManager implements SecretsManagerInterface
{
    private string $key;
    private string $storePath;

    public function __construct(?array $config = null)
    {
        $config ??= require dirname(__DIR__, 3) . '/core/config/vault.php';
        $keyEnvVar = $config['fallback_key_env_var'] ?? 'NMS_ENCRYPTION_KEY';
        $keyHex = getenv($keyEnvVar);

        if (!$keyHex) {
            throw new RuntimeException(
                "Fallback encryption key not set. Set the {$keyEnvVar} environment variable."
            );
        }

        $this->key = hex2bin($keyHex);
        if (strlen($this->key) !== 32) {
            throw new RuntimeException("Encryption key must be 256 bits (64 hex chars).");
        }

        $this->storePath = dirname(__DIR__, 3) . '/storage/secrets.enc.json';
    }

    public function get(string $path): string
    {
        $store = $this->loadStore();
        if (!array_key_exists($path, $store)) {
            throw new RuntimeException("Secret not found: {$path}");
        }
        return $this->decrypt($store[$path]);
    }

    public function put(string $path, string $value): void
    {
        $store = $this->loadStore();
        $store[$path] = $this->encrypt($value);
        $this->saveStore($store);
    }

    public function delete(string $path): void
    {
        $store = $this->loadStore();
        unset($store[$path]);
        $this->saveStore($store);
    }

    public function exists(string $path): bool
    {
        $store = $this->loadStore();
        return array_key_exists($path, $store);
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12); // 96-bit IV for GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Encryption failed");
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException("Invalid encrypted payload");
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException("Decryption failed — invalid key or corrupted data");
        }

        return $plaintext;
    }

    private function loadStore(): array
    {
        if (!file_exists($this->storePath)) {
            return [];
        }
        $content = file_get_contents($this->storePath);
        return json_decode($content ?: '{}', true) ?? [];
    }

    private function saveStore(array $store): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($this->storePath, json_encode($store, JSON_PRETTY_PRINT));
        chmod($this->storePath, 0600);
    }
}
