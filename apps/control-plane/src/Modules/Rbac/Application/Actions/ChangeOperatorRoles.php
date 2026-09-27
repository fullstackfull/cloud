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
 * Sets the staff roles an operator holds, and refuses the five ways that is an
 * escalation.
 *
 * ---------------------------------------------------------------------------
 * Staff roles only: `customer` stays where it is
 * ---------------------------------------------------------------------------
 *
 * The roles given are the login's roles from now on, except `customer` — the
 * baseline every login made through POST /api/v1/register holds — which is
 * kept if the login holds it. The request refuses `customer` by name, so this
 * surface neither gives it nor takes it away. Any other role — a row in the
 * roles table the enum does not declare, which no route creates — is
 * replaced as before: a super admin may remove it, a delegate only if they
 * hold it.
 *
 * Holding `customer` beside a staff role gives no operator authority: a login
 * with a staff role takes no permission from `customer` (User::
 * hasPermissionViaRole()), so a customer role widened by a seeder or a SQL
 * client opens nothing on /api/admin for a promoted customer either.
 *
 * It used to replace the whole set (re-audit after round six). An invitation
 * that promoted a registered customer "removed" `customer`, a role no delegate
 * holds, so a delegate holding `role.manage` was refused 422
 * `rbac.role_not_yours_to_remove` for any address with a registered login and
 * answered 201 for a new one — an existence oracle, and no delegate could
 * ever promote a customer. A super admin's promotion went through and took
 * `customer` away while the customer memberships stayed; and a super admin's
 * `roles: []` on a customer login emptied it. Customer access was never
 * decided by the role — /api/v1 resolves the acting customer from
 * memberships (ResolveActingCustomer), and `customer` carries only
 * `catalog.view`, which no route names outside the operator catalogue's own
 * refusal to use it — so what the replacement changed was what the role says
 * about the person, and the removal rule's answer. A promoted customer is now
 * an operator who is still a customer: the staff gate
 * (EnsureTheCallerIsStaff) admits the login because it holds a staff role,
 * and the customer API serves it as before because it holds memberships.
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
 * The five refusals
 * ---------------------------------------------------------------------------
 *
 *  - Your own account. Grant, use, revoke is the shortest escalation there is,
 *    and there is no legitimate use of it that another operator cannot do.
 *  - A role you do not hold. Otherwise `role.manage` means "every power the
 *    platform has", obtained through an account you create and then sign in as.
 *    A Super Admin holds everything by the Gate bypass, so this only ever binds
 *    the delegated case — which is exactly the case it is for.
 *  - A role you do not hold, taken away. The mirror of the rule above, and
 *    the one that was missing: only the new set was checked, so a support
 *    operator holding `role.manage` could set a super admin's roles to
 *    [support] and demote the top authority (measured: 200), stopped only
 *    when the target happened to be the last one. Judged against the target's
 *    roles read under the row lock, so it sees what is actually removed —
 *    never `customer`, which is kept. Together the two rules mean a delegate
 *    can change only an operator whose roles other than `customer`, before
 *    and after, are all roles the delegate holds. (An invitation is judged
 *    on staff roles only; see change().)
 *  - The last administrator. The console bootstrap refuses once a privileged
 *    operator exists, so a deployment that loses its last one has no supported
 *    way back. With the removal rule in place only a super admin can take the
 *    role away, and never from themselves, so what this binds is the race:
 *    two super admins demoting each other at once, each checked before the
 *    other's change landed. The locked read below settles it.
 *  - A login that holds no staff role (`rbac.not_an_operator`). This route
 *    takes any login's id, and a staff role given to a customer login is
 *    operator authority for whoever holds that login's credentials — a
 *    password somebody chose at POST /api/v1/register without proving the
 *    mailbox (B1, re-audit after round five). A customer login becomes an
 *    operator only through InviteOperator, which takes those credentials away
 *    first; that is grantToInvited(), and nothing else calls it. An operator
 *    whose staff roles were emptied is, for this rule, a customer login again
 *    (and still holds `customer` if it held it before).
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
        return $this->change($actor, $target, $roles, mayPromote: false);
    }

    /**
     * The same change, for InviteOperator only: the one caller allowed to give
     * a staff role to a login that holds none, because it has just taken every
     * credential that login held away (InviteOperator::takeAwayFromWhoeverHeldIt)
     * inside the same transaction.
     *
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    public function grantToInvited(User $actor, User $invited, array $roles): User
    {
        return $this->change($actor, $invited, $roles, mayPromote: true);
    }

    /**
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    private function change(User $actor, User $target, array $roles, bool $mayPromote): User
    {
        if ($actor->is($target)) {
            throw RoleChangeRefusedException::becauseItIsYourOwnAccount();
        }

        $this->assertEveryRoleIsTheActorsToGive($actor, $roles);

        return $this->record->execute(
            act: function () use ($actor, $target, $roles, $mayPromote): User {
                /** @var User $locked */
                $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

                /** @var list<string> $before */
                $before = $locked->getRoleNames()->values()->all();

                if (! $mayPromote && $roles !== [] && ! InviteOperator::holdsAStaffRole($locked)) {
                    throw RoleChangeRefusedException::becauseTheLoginIsNotAnOperator();
                }

                $after = self::keepingCustomer($before, $roles);

                /*
                 * An invitation reaches a login that holds no staff role
                 * (InviteOperator refuses an operator's address and strips a
                 * deleted login's), so whatever else it takes away — a role
                 * row the enum does not declare — goes with the credentials
                 * of whoever held the login, and is not judged against the
                 * inviter's roles: a delegate refused for it would learn the
                 * address had a login, which a new address never produces.
                 */
                $this->assertEveryRemovedRoleIsTheActorsToTake(
                    $actor,
                    $mayPromote ? array_values(array_intersect($before, Role::staffRoleNames())) : $before,
                    $after,
                );

                $this->assertSomebodyIsStillInCharge($locked, $after);

                $locked->syncRoles($after);

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
     * The login's roles after the change: the roles given, and `customer` if
     * it held it. Nothing else it held survives, as before.
     *
     * @param  list<string>  $before  the target's roles, read under the lock
     * @param  list<string>  $given  the roles given (staff roles: the request refuses any other)
     * @return list<string>
     */
    private static function keepingCustomer(array $before, array $given): array
    {
        $kept = in_array(Role::Customer->value, $before, true) ? [Role::Customer->value] : [];

        return array_values(array_unique([...$kept, ...$given]));
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
     * @param  list<string>  $before  the target's roles, read under the lock
     * @param  list<string>  $roles  the new set
     *
     * @throws RoleChangeRefusedException
     */
    private function assertEveryRemovedRoleIsTheActorsToTake(User $actor, array $before, array $roles): void
    {
        if ($actor->hasRole(Role::SuperAdmin->value)) {
            return;
        }

        foreach (array_diff($before, $roles) as $role) {
            if (! $actor->hasRole($role)) {
                throw RoleChangeRefusedException::becauseTheRoleIsNotYoursToRemove($role);
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
