<?php

declare(strict_types=1);

namespace NMS\Core\Auth;

use NMS\Core\Models\Secrets\SecretsManagerInterface;
use RuntimeException;
use stdClass;

/**
 * Machine-to-Machine token issuance and validation.
 *
 * Token types:
 *  - aud: "nms-m2m"  — IMS → NMS (inbound provisioning requests)
 *  - aud: "ims-m2m"  — NMS → IMS (outbound webhook/ticket calls)
 */
class M2MTokenHelper
{
    private JWTHelper $jwt;
    private array $config;

    public function __construct(JWTHelper $jwt, ?array $config = null)
    {
        $this->jwt    = $jwt;
        $this->config = $config ?? require dirname(__DIR__) . '/config/app.php';
    }

    /**
     * Issue a NMS→IMS M2M token (for calling IMS APIs).
     */
    public function issueForIms(): string
    {
        return $this->jwt->generate([
            'sub'         => 'nms-service',
            'aud'         => 'ims-m2m',
            'exp'         => time() + 3600,
            'type'        => 'm2m',
            'client_id'   => $this->config['ims']['m2m_client_id'] ?? 'nms',
            'permissions' => ['ims.ticket.create', 'ims.ticket.update', 'ims.server.read'],
        ]);
    }

    /**
     * Issue an IMS→NMS M2M token (for external callers).
     * Normally IMS generates these, but NMS can generate them for testing.
     */
    public function issueForNms(array $permissions = []): string
    {
        return $this->jwt->generate([
            'sub'         => 'ims-service',
            'aud'         => 'nms-m2m',
            'exp'         => time() + 3600,
            'type'        => 'm2m',
            'permissions' => $permissions ?: ['nms.provision.*', 'nms.device.read'],
        ]);
    }

    /**
     * Validate an inbound M2M token from IMS.
     * Enforces aud = "nms-m2m".
     *
     * @throws RuntimeException if invalid or wrong audience
     */
    public function validateInbound(string $token): stdClass
    {
        $claims = $this->jwt->validate($token);

        if (!isset($claims->aud) || $claims->aud !== 'nms-m2m') {
            throw new RuntimeException('Invalid M2M token: wrong audience', 401);
        }

        if (!isset($claims->type) || $claims->type !== 'm2m') {
            throw new RuntimeException('Invalid M2M token: wrong token type', 401);
        }

        return $claims;
    }

    /**
     * Validate an outbound M2M token (NMS→IMS).
     * Enforces aud = "ims-m2m".
     */
    public function validateOutbound(string $token): stdClass
    {
        $claims = $this->jwt->validate($token);

        if (!isset($claims->aud) || $claims->aud !== 'ims-m2m') {
            throw new RuntimeException('Invalid M2M token: wrong audience', 401);
        }

        return $claims;
    }

    /**
     * Check if a permission is present in M2M token claims.
     * Supports wildcard patterns like "nms.provision.*".
     */
    public function hasPermission(stdClass $claims, string $required): bool
    {
        $permissions = (array)($claims->permissions ?? []);

        foreach ($permissions as $perm) {
            if ($perm === $required) {
                return true;
            }
            // Wildcard match: "nms.provision.*" matches "nms.provision.execute"
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($required, $prefix . '.')) {
                    return true;
                }
            }
        }
        return false;
    }
}
