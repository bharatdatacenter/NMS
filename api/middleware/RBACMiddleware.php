<?php

declare(strict_types=1);

namespace NMS\Api\Middleware;

use NMS\Core\Helpers\Response;
use stdClass;

/**
 * RBACMiddleware — reads permissions[] from JWT claim. NO MongoDB query.
 *
 * NMS does not own RBAC — IMS does. This middleware only checks the
 * permissions already embedded in the JWT token.
 */
class RBACMiddleware
{
    /**
     * Check that the authenticated user has the required permission.
     * Supports wildcard permissions like "nms.device.*".
     *
     * @param stdClass $claims           Decoded JWT claims from AuthMiddleware
     * @param string   $requiredPerm     Permission string to check
     */
    public static function handle(stdClass $claims, string $requiredPerm): void
    {
        $permissions = (array)($claims->permissions ?? []);

        if (self::hasPermission($permissions, $requiredPerm)) {
            return;
        }

        Response::forbidden("Insufficient permissions. Required: {$requiredPerm}");
    }

    /**
     * Check multiple permissions (all must be present).
     */
    public static function requireAll(stdClass $claims, array $requiredPerms): void
    {
        $permissions = (array)($claims->permissions ?? []);
        foreach ($requiredPerms as $perm) {
            if (!self::hasPermission($permissions, $perm)) {
                Response::forbidden("Insufficient permissions. Required: {$perm}");
            }
        }
    }

    /**
     * Check if any of the given permissions is present.
     */
    public static function requireAny(stdClass $claims, array $requiredPerms): void
    {
        $permissions = (array)($claims->permissions ?? []);
        foreach ($requiredPerms as $perm) {
            if (self::hasPermission($permissions, $perm)) {
                return;
            }
        }
        Response::forbidden('Insufficient permissions');
    }

    /**
     * Check permission with wildcard support.
     * "nms.provision.*" grants "nms.provision.execute", "nms.provision.rollback", etc.
     */
    private static function hasPermission(array $permissions, string $required): bool
    {
        foreach ($permissions as $perm) {
            if ($perm === $required) {
                return true;
            }
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($required, $prefix . '.') || $required === $prefix) {
                    return true;
                }
            }
        }
        return false;
    }
}
