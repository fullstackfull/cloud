<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Rbac\Domain\Exceptions\RoleChangeRefusedException;

/**
 * Sets the roles an operator holds, and refuses the three ways that is an
 * escalation.
 *
 * ---------------------------------------------------------------------------
 * Why the checks are here and not in the controller
 * ---------------------------------------------------------------------------
 *
 * A form request can say "these are role names". It cannot say "and the person
 * asking holds them", because that needs the actor, the target and the count of
 * everybody else who could administer the platform — and the last of those has
 * to be read inside the transaction that changes it, or two operators giving up
 * the role at the same time both see somebody else holding it.
 *
 * ---------------------------------------------------------------------------
 * The three refusals
 * ---------------------------------------------------------------------------
 *
 *  - Your own account. Grant, use, revoke is the shortest escalation there is,
 *    and there is no legitimate use of it that another operator cannot do.
 *  - A role you do not hold. Otherwise `role.manage` means "every power the
 *    platform has", obtained through an account you create and then sign in as.
 *    A Super Admin holds everything by the Gate bypass, so this only ever binds
 *    the delegated case — which is exactly the case it is for.
 *  - The last administrator. The console bootstrap refuses once a privileged
 *    operator exists, so a deployment that loses its last one has no supported
 *    way back.
 */
final readonly class ChangeOperatorRoles
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    public function execute(User $actor, User $target, array $roles): User
    {
        if ($actor->is($target)) {
            throw RoleChangeRefusedException::becauseItIsYourOwnAccount();
        }

        $this->assertEveryRoleIsTheActorsToGive($actor, $roles);

        return $this->record->execute(
            act: function () use ($target, $roles): User {
                /** @var User $locked */
                $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

                $before = $locked->getRoleNames()->values()->all();

                $this->assertSomebodyIsStillInCharge($locked, $roles);

                $locked->syncRoles($roles);

                // Carried on the instance so that describe() can report both
                // sides without reading a row the sync has already changed.
                $locked->setAttribute('roles_before_change', $before);

                return $locked;
            },
            describe: static fn (User $changed): AuditedAct => new AuditedAct(
                action: AuditAction::OperatorRolesChanged,
                subject: $changed,
                context: [
                    'operator' => $changed->email,
                    'before' => $changed->getAttribute('roles_before_change'),
                    'after' => $changed->fresh()?->getRoleNames()->values()->all() ?? [],
                ],
            ),
        );
    }

    /**
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    private function assertEveryRoleIsTheActorsToGive(User $actor, array $roles): void
    {
        if ($actor->hasRole(Role::SuperAdmin->value)) {
            return;
        }

        foreach ($roles as $role) {
            if (! $actor->hasRole($role)) {
                throw RoleChangeRefusedException::becauseTheRoleIsNotYoursToGrant($role);
            }
        }
    }

    /**
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    private function assertSomebodyIsStillInCharge(User $target, array $roles): void
    {
        $keepsIt = in_array(Role::SuperAdmin->value, $roles, true);

        if ($keepsIt || ! $target->hasRole(Role::SuperAdmin->value)) {
            return;
        }

        /*
         * Read under the same lock the change is made with, and excluding the
         * target: two operators each giving up the role at the same moment
         * would otherwise each see the other still holding it, and the
         * deployment would end up with none.
         *
         * Rows rather than a count, because PostgreSQL refuses FOR UPDATE
         * beside an aggregate — and `of users` rather than a bare FOR UPDATE,
         * so this does not also lock the `roles` row that every permission
         * change in the platform touches.
         */
        $others = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', (new User)->getMorphClass());
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Role::SuperAdmin->value)
            ->whereNull('users.deleted_at')
            ->where('users.id', '!=', $target->getKey())
            ->select('users.id')
            ->lock('for update of users')
            ->limit(1)
            ->get();

        if ($others->isEmpty()) {
            throw RoleChangeRefusedException::becauseItWouldLeaveNobodyInCharge();
        }
    }
}
