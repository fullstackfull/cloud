<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Lynomia\Modules\Rbac\Domain\Enums\Permission as PermissionEnum;
use Lynomia\Modules\Rbac\Domain\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the platform's permissions and default roles.
 *
 * Idempotent: running it again after adding a permission to the enum adds the
 * new rows and re-syncs role assignments without disturbing custom roles an
 * operator has created, and without revoking anything granted manually to a
 * user.
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            // Super Admin is granted everything through a Gate::before rule
            // rather than an explicit list, so that a permission added later is
            // never accidentally missing from it.
            if ($roleEnum === RoleEnum::SuperAdmin) {
                continue;
            }

            $role->syncPermissions(
                array_map(
                    static fn (PermissionEnum $permission): string => $permission->value,
                    $roleEnum->defaultPermissions(),
                )
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
