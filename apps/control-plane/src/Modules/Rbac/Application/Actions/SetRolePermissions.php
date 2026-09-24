<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role as RoleEnum;
use Lynomia\Modules\Rbac\Domain\Exceptions\RoleChangeRefusedException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What a role may do, set by an operator rather than by a seeder run.
 *
 * Eleven permissions were held by no role — defensible under a bypass model,
 * where they are super-admin-only until somebody decides otherwise, but only
 * if somebody can decide. There was no route through which anybody could, so
 * the answer to "give the NOC shift the ability to declare readiness" was a
 * SQL client.
 *
 * Super Admin is refused. Its authority comes from the Gate::before bypass and
 * not from the rows attached to it, so an operator editing that list would be
 * changing something that does not decide anything — and would reasonably
 * believe they had restricted it.
 */
final readonly class SetRolePermissions
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  list<string>  $permissions
     *
     * @throws RoleChangeRefusedException
     */
    public function execute(User $actor, Role $role, array $permissions): Role
    {
        if ($role->name === RoleEnum::SuperAdmin->value) {
            throw RoleChangeRefusedException::becauseTheRoleIsProtected($role->name);
        }

        /*
         * An operator may not grant through a role what they could not grant
         * directly. Without this, `role.manage` on its own is every permission
         * the platform has: edit a role you hold, then use it.
         */
        if (! $actor->hasRole(RoleEnum::SuperAdmin->value)) {
            foreach ($permissions as $permission) {
                if (! $actor->can($permission)) {
                    throw RoleChangeRefusedException::becauseTheRoleIsNotYoursToGrant($permission);
                }
            }
        }

        return $this->record->execute(
            act: function () use ($role, $permissions): Role {
                $before = $role->permissions()->pluck('name')->values()->all();

                $role->syncPermissions($permissions);

                // The registrar caches the whole map; a grant that is not
                // visible until the next request is a grant an operator will
                // reasonably believe did not happen.
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $role->setAttribute('permissions_before_change', $before);

                return $role;
            },
            describe: static fn (Role $changed): AuditedAct => new AuditedAct(
                action: AuditAction::RolePermissionsChanged,
                subject: $changed,
                context: [
                    'role' => $changed->name,
                    'before' => $changed->getAttribute('permissions_before_change'),
                    'after' => $changed->permissions()->pluck('name')->values()->all(),
                ],
            ),
        );
    }
}
