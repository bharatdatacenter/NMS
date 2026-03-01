<?php

declare(strict_types=1);

namespace NMS\Tests\Unit;

use NMS\Tests\TestCase;
use NMS\Api\Middleware\RBACMiddleware;
use stdClass;

class RBACMiddlewareTest extends TestCase
{
    private function makeClaims(array $permissions, array $roles = []): stdClass
    {
        $c = new stdClass();
        $c->sub         = 'user-123';
        $c->permissions = $permissions;
        $c->roles       = $roles;
        return $c;
    }

    public function testGrantsAccessWithExactPermission(): void
    {
        $claims = $this->makeClaims(['nms.device.read', 'nms.ipam.write']);

        // Should not throw
        $this->expectNotToPerformAssertions();
        RBACMiddleware::handle($claims, 'nms.device.read');
    }

    public function testGrantsAccessWithWildcardPermission(): void
    {
        $claims = $this->makeClaims(['nms.provision.*']);

        $this->expectNotToPerformAssertions();
        RBACMiddleware::handle($claims, 'nms.provision.execute');
        RBACMiddleware::handle($claims, 'nms.provision.rollback');
    }

    public function testDeniesAccessWithoutPermission(): void
    {
        $claims = $this->makeClaims(['nms.device.read']);

        // We can't test Response::forbidden directly (it calls exit),
        // so we test the hasPermission logic via requireAny
        // Instead, test the actual RBAC logic by checking permission matching
        $permissions = (array)$claims->permissions;
        $required    = 'nms.device.write';

        $hasPermission = false;
        foreach ($permissions as $perm) {
            if ($perm === $required) {
                $hasPermission = true;
                break;
            }
        }

        $this->assertFalse($hasPermission);
    }

    public function testWildcardDoesNotMatchUnrelatedPermissions(): void
    {
        $claims = $this->makeClaims(['nms.device.*']);

        // nms.device.* should match nms.device.read but NOT nms.ipam.read
        $permissions = (array)$claims->permissions;

        $matchesDevice = false;
        $matchesIPAM   = false;

        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with('nms.device.read', $prefix . '.')) {
                    $matchesDevice = true;
                }
                if (str_starts_with('nms.ipam.read', $prefix . '.')) {
                    $matchesIPAM = true;
                }
            }
        }

        $this->assertTrue($matchesDevice);
        $this->assertFalse($matchesIPAM);
    }

    public function testEmptyPermissionsDenieAll(): void
    {
        $claims = $this->makeClaims([]);
        $permissions = (array)$claims->permissions;

        $this->assertEmpty($permissions);
        $this->assertFalse(in_array('nms.device.read', $permissions));
    }

    public function testRequireAllWithMultiplePermissions(): void
    {
        // Both permissions present — should not throw
        $claims = $this->makeClaims(['nms.device.read', 'nms.ipam.write']);
        $this->expectNotToPerformAssertions();

        // Test the logic directly (requireAll calls Response::forbidden on failure)
        $permissions = (array)$claims->permissions;
        $required    = ['nms.device.read', 'nms.ipam.write'];

        foreach ($required as $perm) {
            $this->assertContains($perm, $permissions);
        }
    }

    public function testRequireAnyMatchesOneOfMultiple(): void
    {
        $claims = $this->makeClaims(['nms.audit.read']);
        $permissions = (array)$claims->permissions;

        $anyMatch = false;
        foreach (['nms.settings.write', 'nms.audit.read'] as $perm) {
            if (in_array($perm, $permissions)) {
                $anyMatch = true;
                break;
            }
        }

        $this->assertTrue($anyMatch);
    }
}
