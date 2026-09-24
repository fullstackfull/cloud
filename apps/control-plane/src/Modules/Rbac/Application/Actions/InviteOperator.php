<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Application\Actions;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
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
 * An existing user is promoted rather than refused: the people who operate a
 * platform often already have a customer login, and making them register a
 * second address to hold a role would be a rule with no purpose.
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
    public function execute(User $actor, string $email, string $name, array $roles): User
    {
        $address = Str::lower(trim($email));

        $operator = $this->record->execute(
            act: function () use ($address, $name): User {
                /** @var User $user */
                $user = User::query()->firstOrNew(['email' => $address]);

                if (! $user->exists) {
                    $user->forceFill([
                        'name' => $name,
                        // Nobody's to know, including the operator who asked
                        // for the account: the reset link is the way in.
                        'password' => Str::random(64),
                        'email_verified_at' => now(),
                    ])->save();
                }

                return $user;
            },
            describe: static fn (User $user): AuditedAct => new AuditedAct(
                action: AuditAction::OperatorInvited,
                subject: $user,
                context: ['email' => $user->email, 'name' => $user->name],
            ),
        );

        /*
         * The roles go on through the ordinary path, so an invitation cannot
         * hand out authority that a direct role change would have refused.
         * Written this way round on purpose: the alternative — validating here
         * and assigning here — is a second copy of the escalation rules.
         */
        $this->roles->execute($actor, $operator, $roles);

        Password::sendResetLink(['email' => $operator->email]);

        return $operator->fresh() ?? $operator;
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

        return $user->getRoleNames()
            ->contains(static fn (string $role): bool => Role::tryFrom($role)?->isStaffRole() === true);
    }
}
