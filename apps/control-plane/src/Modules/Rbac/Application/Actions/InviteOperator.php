<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\DTOs\InvitedOperator;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Rbac\Domain\Exceptions\RoleChangeRefusedException;

/**
 * The second operator, and every one after it, created inside the platform.
 *
 * This is what makes the console bootstrap a bootstrap rather than an ongoing
 * way in. Before it existed, a deployment could have exactly as many operators
 * as it was born with — and it was born with none.
 *
 * No password is chosen here either. The person takes the account over through
 * the one-time reset link the platform already issues, which means there is
 * never a moment where a credential somebody else picked opens an operator
 * account, and nothing to communicate out of band.
 *
 * An existing login is promoted rather than refused: the people who operate a
 * platform often already have a customer login, and making them register a
 * second address to hold a role would be a rule with no purpose. But
 * POST /api/v1/register asks for no proof of the mailbox, so a login under an
 * address is not evidence that the address's owner made it. It used to keep
 * its password (B1, re-audit after round five: somebody registered the
 * address first, signed in with their own password after the invitation and,
 * once the mailbox owner followed the verification link, read the admin
 * surface). So before the roles go on, an existing login loses every
 * credential nobody at the platform issued for this purpose — see
 * takeAwayFromWhoeverHeldIt() — and the reset link is the only way in.
 *
 * Verification is left exactly as it was: an unverified login stays
 * unverified, and `verified` keeps it off /api/admin, until the mailbox owner
 * completes the reset — which proves the mailbox, and so verifies the address
 * (PasswordResetController). Before that nobody holds a credential; after it
 * only the mailbox owner does. There is no window in between.
 *
 * Its customer memberships stay with it. They belong to the login, and the
 * login from then on belongs to whoever proves the mailbox; dropping them
 * would take a legitimate customer's account away from its owner on
 * promotion, and would leave an account the registrant built with no owner.
 * Customer membership is not operator authority either way. So does its
 * `customer` role: the staff roles are added beside it, not put in its place
 * (ChangeOperatorRoles sets staff roles only), so the person is an operator
 * and still a customer — and a delegate promoting a registered customer is
 * not refused for "removing" a role it does not hold, which used to tell it
 * that the address had a login (re-audit after round six).
 *
 * A deleted login's address is invited the same way: the row is restored and
 * promoted, without the staff roles it held when it was deleted — see
 * bringBack().
 *
 * The whole invitation is one transaction: the user row, the revocation, its
 * OperatorInvited entry and the role grant commit together or not at all. The
 * grant used to run after the first two had committed, so a refused grant
 * (422 `rbac.role_not_yours_to_grant`) left a user row and an audit entry
 * recording an invitation that never happened. The reset link is sent only
 * after the commit.
 */
final readonly class InviteOperator
{
    public function __construct(
        private RecordActAtomically $record,
        private ChangeOperatorRoles $roles,
    ) {}

    /**
     * @param  list<string>  $roles
     *
     * @throws RoleChangeRefusedException
     */
    public function execute(User $actor, string $email, string $name, array $roles): InvitedOperator
    {
        $address = Str::lower(trim($email));

        [$operator, $promoted] = DB::transaction(fn (): array => $this->inviteAndGrant($actor, $address, $name, $roles));

        Password::sendResetLink(['email' => $operator->email]);

        return new InvitedOperator($operator->fresh() ?? $operator, $promoted);
    }

    /**
     * @param  list<string>  $roles
     * @return array{0: User, 1: bool} the operator, and whether it was an existing login
     *
     * @throws RoleChangeRefusedException
     */
    private function inviteAndGrant(User $actor, string $address, string $name, array $roles): array
    {
        $promoted = false;
        /** @var list<string>|null $restored the staff roles a restored deleted login held, or null */
        $restored = null;
        /** @var list<string> $revoked the permissions an existing login held directly, now revoked */
        $revoked = [];

        $operator = $this->record->execute(
            act: function () use ($address, $name, &$promoted, &$restored, &$revoked): User {
                /*
                 * A deleted login included: its row still owns the address
                 * (users_email_unique), so looking past it and inserting was
                 * a unique violation and a 500 — for a delegate, a status no
                 * new address produces (B7-2, re-audit after round six).
                 */
                /** @var User|null $existing */
                $existing = User::query()->withTrashed()->where('email', $address)->lockForUpdate()->first();

                if ($existing !== null) {
                    $promoted = true;

                    if ($existing->trashed()) {
                        $restored = $this->bringBack($existing);
                    }

                    $revoked = $this->takeAwayFromWhoeverHeldIt($existing);

                    return $existing;
                }

                $user = new User;
                $user->forceFill([
                    'email' => $address,
                    'name' => $name,
                    // Nobody's to know, including the operator who asked
                    // for the account: the reset link is the way in.
                    'password' => Str::random(64),
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            },
            describe: static function (User $user) use (&$restored, &$revoked): AuditedAct {
                $context = ['email' => $user->email, 'name' => $user->name];

                if ($revoked !== []) {
                    $context['direct_permissions_revoked'] = $revoked;
                }

                if ($restored !== null) {
                    // The restoration and the roles it did not bring back are
                    // recorded here; the role change that follows sees the
                    // login only after them.
                    $context['restored_deleted_login'] = true;
                    $context['staff_roles_held_when_deleted'] = $restored;
                }

                return new AuditedAct(action: AuditAction::OperatorInvited, subject: $user, context: $context);
            },
        );

        /*
         * The roles go on through the ordinary path, so an invitation cannot
         * hand out authority that a direct role change would have refused.
         * Written this way round on purpose: the alternative — validating here
         * and assigning here — is a second copy of the escalation rules.
         */
        return [$this->roles->grantToInvited($actor, $operator, $roles), $promoted];
    }

    /**
     * Every credential on an existing login that the platform did not issue
     * for this invitation, made worthless before the login holds a staff role.
     *
     *  - the password: replaced by a random one nobody knows; the reset link
     *    sets the next one. This alone also ends every cookie session, because
     *    Sanctum's AuthenticateSession compares the password hash it stored at
     *    sign-in, and every remember-me cookie, which carries that hash too;
     *  - the `remember_token`, rotated, so a recaller is dead on its own;
     *  - every `sessions` row, deleted, so no device keeps a session;
     *  - every personal access token, deleted;
     *  - the second factor — secret, recovery codes, confirmation — cleared,
     *    so an authenticator the registrant enrolled neither opens the
     *    account nor stands between the mailbox owner and it;
     *  - `last_login_at` / `last_login_ip`, cleared, so `has_signed_in` on the
     *    operator screen says whether anybody has signed in since the account
     *    became reachable only through the reset link (the sign-ins before
     *    it stay in login_activities);
     *  - any reset token already outstanding for the address, deleted. It went
     *    to the mailbox and is harmless, but the broker refuses a second link
     *    to the same address inside its throttle window, and the invitation's
     *    own link would then not be sent at all;
     *  - every permission given to the login directly, revoked (recorded on
     *    the OperatorInvited entry). A login with no staff role cannot use
     *    one — the staff gate refuses it — but once the roles land it would
     *    be operator authority nobody granted in this invitation, and beyond
     *    what a delegate may give.
     *
     * Reached only for a login that holds no staff role: the request refuses
     * an address that already belongs to an operator, and bringBack() takes
     * a deleted login's staff roles away before it gets here.
     *
     * @return list<string> the permissions the login held directly, now revoked
     */
    private function takeAwayFromWhoeverHeldIt(User $user): array
    {
        $user->forceFill([
            'password' => Str::random(64),
            'remember_token' => Str::random(60),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
        ])->save();

        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->tokens()->delete();
        Password::broker()->deleteToken($user);

        /** @var list<string> $direct */
        $direct = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();

        if ($direct !== []) {
            $user->syncPermissions([]);
        }

        return $direct;
    }

    /**
     * A deleted login under the invited address, restored so it can be
     * promoted like any other existing login.
     *
     * Nothing in the application deletes a login, so a deleted one was
     * deleted by hand; the address is still its, and the alternatives are a
     * refusal — which a delegate would see where a new address gets 201 — or
     * a second row the unique index forbids.
     *
     * What this does: clears `deleted_at`, and takes away every staff role
     * the row held. A deleted login is nobody's operator account (the guard
     * does not load it), so its old authority does not come back with it;
     * taking it here, rather than through the role change, also keeps the
     * removal rule from refusing a delegate for a role that meant nothing —
     * which would tell that delegate the address had a login.
     *
     * What it keeps is everything else on the row and hanging off it, as for
     * any promoted login: the stored name, its customer memberships, legal
     * acceptances and login history, and its non-staff roles (the role
     * change that follows keeps `customer` and drops any other). Its
     * credentials and any permission given to it directly go next, in
     * takeAwayFromWhoeverHeldIt().
     *
     * @return list<string> the staff roles it held, now taken away
     */
    private function bringBack(User $user): array
    {
        $user->restore();

        /** @var list<string> $staff */
        $staff = $user->getRoleNames()
            ->filter(static fn (string $role): bool => Role::tryFrom($role)?->isStaffRole() === true)
            ->values()
            ->all();

        foreach ($staff as $role) {
            $user->removeRole($role);
        }

        return $staff;
    }

    /**
     * Whether this address already belongs to somebody who holds a staff role.
     *
     * Used by the request to refuse a second invitation rather than silently
     * resetting an operator's roles through a creation endpoint.
     */
    public static function alreadyAnOperator(string $email): bool
    {
        /** @var User|null $user */
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user === null) {
            return false;
        }

        return self::holdsAStaffRole($user);
    }

    /**
     * Whether a login holds any staff role — the line between a customer
     * login and an operator.
     */
    public static function holdsAStaffRole(User $user): bool
    {
        return $user->getRoleNames()
            ->contains(static fn (string $role): bool => Role::tryFrom($role)?->isStaffRole() === true);
    }
}
