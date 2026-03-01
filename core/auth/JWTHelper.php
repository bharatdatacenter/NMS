<?php

declare(strict_types=1);

namespace NMS\Core\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use NMS\Core\Models\Secrets\SecretsManagerInterface;
use RuntimeException;
use stdClass;

/**
 * JWT generation and validation with dual-key rotation support.
 *
 * Dual-key rotation: NMS can accept tokens signed by either the current key
 * or the previous key during a rotation window. New tokens are always signed
 * with the current (primary) key.
 */
class JWTHelper
{
    private string $signingKey;
    private ?string $previousKey;
    private array $config;

    public function __construct(SecretsManagerInterface $secrets, ?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__) . '/config/app.php';
        $vaultConfig  = require dirname(__DIR__) . '/config/vault.php';

        $this->signingKey  = $secrets->get($vaultConfig['paths']['jwt_secret']);
        // Previous key for rotation window — optional
        try {
            $this->previousKey = $secrets->get($vaultConfig['paths']['jwt_secret'] . '_previous');
        } catch (RuntimeException) {
            $this->previousKey = null;
        }
    }

    /**
     * Generate a JWT token with the given claims.
     */
    public function generate(array $claims): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => $this->config['jwt']['issuer'] ?? 'nms',
            'iat' => $now,
            'nbf' => $now,
            'jti' => bin2hex(random_bytes(16)),
        ], $claims);

        return JWT::encode($payload, $this->signingKey, 'HS256');
    }

    /**
     * Generate a user access token.
     */
    public function generateAccessToken(array $user): string
    {
        $expiry = $this->config['jwt']['expiry'] ?? 900;
        return $this->generate([
            'sub'         => $user['id'],
            'aud'         => $this->config['jwt']['audience'] ?? 'nms',
            'exp'         => time() + $expiry,
            'type'        => 'access',
            'roles'       => $user['roles'] ?? [],
            'permissions' => $user['permissions'] ?? [],
        ]);
    }

    /**
     * Generate a one-time refresh token.
     */
    public function generateRefreshToken(array $user): string
    {
        $expiry = $this->config['jwt']['refresh_expiry'] ?? 604800;
        return $this->generate([
            'sub'  => $user['id'],
            'aud'  => 'nms-refresh',
            'exp'  => time() + $expiry,
            'type' => 'refresh',
        ]);
    }

    /**
     * Validate a token. Tries current key first, then previous key (rotation window).
     *
     * @throws RuntimeException if invalid
     */
    public function validate(string $token): stdClass
    {
        $keys = [new Key($this->signingKey, 'HS256')];
        if ($this->previousKey) {
            $keys[] = new Key($this->previousKey, 'HS256');
        }

        $lastException = null;
        foreach ($keys as $key) {
            try {
                return JWT::decode($token, $key);
            } catch (ExpiredException $e) {
                throw new RuntimeException('Token has expired', 401, $e);
            } catch (BeforeValidException $e) {
                throw new RuntimeException('Token not yet valid', 401, $e);
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }

        throw new RuntimeException('Invalid token signature', 401, $lastException);
    }

    /**
     * Decode token without verification — for extracting claims from an
     * already-validated token (e.g., to get jti for blocklist).
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token');
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid token payload');
        }
        return $payload;
    }

    /**
     * Get the remaining TTL of a token in seconds.
     */
    public function getRemainingTtl(string $token): int
    {
        try {
            $claims = $this->decode($token);
            $exp = $claims['exp'] ?? 0;
            return max(0, $exp - time());
        } catch (\Exception) {
            return 0;
        }
    }
}
