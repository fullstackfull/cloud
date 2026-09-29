<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * An operator holding exactly the permissions a test names, and nothing else.
 *
 * Tests that ask "does this route want this permission and no other" used to
 * grant permissions straight to a login holding no role at all. Since the
 * /api/admin staff gate (EnsureTheCallerIsStaff, OB-1 of the re-audit of round
 * three) a login with no staff role is refused before any permission is read,
 * so such a login is refused whatever it holds: its 403s would pass for the
 * wrong reason and its 200s would fail.
 *
 * The login here holds one staff role, Finance, whose permission rows are
 * emptied first — inside the test's own transaction, so the seeded role is
 * back for the next test. Everything the login can do is therefore exactly
 * the permissions passed. A test that also needs Finance's seeded permissions
 * for another login must not use this.
 */
trait StaffHoldingExactly
{
    /**
     * @param  list<Permission|string>  $permissions
     */
    protected function staffHoldingExactly(array $permissions): User
    {
        SpatieRole::findOrCreate(Role::Finance->value, 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(Role::Finance->value);

        if ($permissions !== []) {
            $user->givePermissionTo(array_map(
                static fn (Permission|string $permission): string => $permission instanceof Permission ? $permission->value : $permission,
                $permissions,
            ));
        }

        return $user;
    }
}
