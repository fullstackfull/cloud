<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lynomia\Http\Rules\ALoginAddressThatSplitsWhereWritten;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;

/**
 * Establishes the first operator a deployment has, and then refuses.
 *
 * ---------------------------------------------------------------------------
 * The paradox this exists for, and nothing else
 * ---------------------------------------------------------------------------
 *
 * Creating an operator is an administrative act, and administrative acts
 * belong behind an authenticated operator surface. That is circular exactly
 * once — for the first one — and this command is the smallest thing that
 * breaks the circle. Every operator after the first is created through the
 * platform, by the first, and this command will not help: it fails as soon as
 * a privileged principal exists.
 *
 * Before it existed, a production deployment came up with the whole permission
 * catalogue defined and nobody holding any of it. RolePermissionSeeder assigns
 * no user, correctly — a seeder that creates a privileged account creates it
 * with a password that is in Git — and DevelopmentSeeder, which does assign
 * one, refuses to run in production, also correctly. The only ways through
 * were a SQL client, an edited seeder or tinker, which is the defect.
 *
 * ---------------------------------------------------------------------------
 * It sets no password
 * ---------------------------------------------------------------------------
 *
 * There is no default credential here, nothing to commit, nothing to print
 * into a CI log and nothing an operator has to be told to change afterwards.
 * The account is created with a random secret nobody sees, and the person
 * takes it over through the password-reset link the platform already issues —
 * single-use and expiring, by the broker's own rules, rather than by a second
 * token mechanism invented here.
 *
 * `--show-link` prints that link instead of mailing it, for the first
 * deployment of all, where mail is not configured yet. It is opt-in because a
 * link on a terminal is a link in a scrollback buffer.
 *
 * ---------------------------------------------------------------------------
 * Single use, by state rather than by a flag
 * ---------------------------------------------------------------------------
 *
 * The condition is "does a privileged principal already exist", asked of the
 * database at the moment of the attempt. A stored "bootstrap completed" flag
 * would be a second source of truth that can disagree with the first, and the
 * disagreement is a way to mint a second super admin from the console.
 *
 * "At the moment of the attempt" means under a lock, in the transaction that
 * creates the account. The question used to be asked before any transaction:
 * two runs at once each counted no super admin and each created one — two in
 * four of six rounds, measured by the re-audit of round three (OB-2). Now
 * both questions (a super admin exists; the address is taken) are asked again
 * after a transaction-scoped advisory lock on BOOTSTRAP_LOCK, and the account,
 * its role and its audit entry commit in that same transaction. A second run
 * waits for the first to commit, then counts one and refuses. The checks
 * before the transaction stay only to answer early without taking the lock;
 * they decide nothing. TwoBootstrapsAtOnceEstablishOneOperatorTest races
 * separate processes against it.
 *
 * ---------------------------------------------------------------------------
 * A new account, never somebody else's
 * ---------------------------------------------------------------------------
 *
 * The address must not already belong to a login, soft-deleted ones included.
 * This used firstOrNew, so on a deployment with no super admin it would have
 * taken an existing customer login with that address, made it super admin and
 * replaced its password: the top authority handed to whoever reads that
 * customer's mailbox, and the customer locked out. The first operator is
 * created under an address of its own; once it exists, an existing login is
 * promoted deliberately, through the Control Center's invitation.
 */
final class BootstrapFirstOperator extends Command
{
    protected $signature = 'operator:bootstrap
        {email : The address the first operator will sign in with}
        {--name= : Their name, as it should appear in the audit trail}
        {--show-link : Print the one-time link instead of mailing it}';

    protected $description = 'Establish the first privileged operator on a fresh deployment.';

    /** The key every run serialises on; see "Single use, by state". */
    private const string BOOTSTRAP_LOCK = 'operator:bootstrap';

    public function handle(RecordActAtomically $record): int
    {
        // Early answer only; asked again under the lock below.
        $refusal = $this->alreadyEstablished();

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $email = LoginAddress::normalise((string) $this->argument('email'));
        $name = trim((string) ($this->option('name') ?? '')) ?: 'Platform Operator';

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'string', 'email', 'max:255', new ALoginAddressThatSplitsWhereWritten],
                'name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Early answer only; asked again under the lock below.
        $refusal = $this->addressTaken($email);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $operator = $record->execute(
            act: function () use ($email, $name, &$refusal): ?User {
                // Before either question is asked, inside the transaction the
                // account commits in: a concurrent run waits here until this
                // one has committed or rolled back.
                DB::statement('select pg_advisory_xact_lock(hashtext(?))', [self::BOOTSTRAP_LOCK]);

                $refusal = $this->alreadyEstablished() ?? $this->addressTaken($email);

                if ($refusal !== null) {
                    return null;
                }

                $user = new User;
                $user->forceFill(['email' => $email]);

                $user->forceFill([
                    'name' => $name,
                    /*
                     * A secret nobody has, not a placeholder. The account is
                     * unusable until the reset link is followed, which is the
                     * point: there is no window in which a known credential
                     * opens a privileged account.
                     */
                    'password' => Str::random(64),
                    /*
                     * Asserted by whoever runs this command, which is the same
                     * authority that owns the database. The operator surfaces
                     * are behind `verified`, and a first operator who cannot
                     * reach them is another dead end.
                     */
                    'email_verified_at' => now(),
                    'password_changed_at' => null,
                ])->save();

                $user->assignRole(Role::SuperAdmin->value);

                return $user;
            },
            describe: static fn (?User $user): ?AuditedAct => $user === null ? null : new AuditedAct(
                action: AuditAction::OperatorBootstrapped,
                subject: $user,
                // The act, and nothing that could be used: no token, no link,
                // no secret.
                context: ['email' => $user->email, 'name' => $user->name, 'role' => Role::SuperAdmin->value],
            ),
        );

        if ($operator === null) {
            $this->error((string) $refusal);

            return self::FAILURE;
        }

        $this->deliverTheOneTimeLink($operator);

        $this->info(sprintf('%s is now the first operator of this deployment.', $operator->email));
        $this->line('Every operator after this one is created in the Control Center. This command will now refuse.');

        return self::SUCCESS;
    }

    /**
     * Why the deployment already has its first operator, or null.
     */
    private function alreadyEstablished(): ?string
    {
        $existing = User::query()->role(Role::SuperAdmin->value)->count();

        if ($existing === 0) {
            return null;
        }

        return sprintf(
            'This deployment already has %d privileged operator(s). Create further operators through '
            .'the Control Center, not from the console.',
            $existing,
        );
    }

    /**
     * Why this address cannot be the first operator's, or null.
     */
    private function addressTaken(string $email): ?string
    {
        if (! User::query()->withTrashed()->where('email', $email)->exists()) {
            return null;
        }

        return 'That address already belongs to an account on this deployment. The first operator is created '
            .'as a new account: use an address of its own. An existing account can be given a role through '
            .'the Control Center once the first operator exists.';
    }

    /**
     * Hands over the account without ever choosing a password for it.
     */
    private function deliverTheOneTimeLink(User $operator): void
    {
        if ($this->option('show-link') !== true) {
            Password::sendResetLink(['email' => $operator->email]);

            $this->line(sprintf('A one-time sign-in link has been sent to %s.', $operator->email));

            return;
        }

        /*
         * The facade's own broker, not one resolved by hand: `createToken()`
         * is declared on `Password` and is only on the concrete broker
         * underneath, so reaching through `broker()` first would trade a
         * typed call for an untyped one and buy nothing.
         */
        $token = Password::createToken($operator);

        $this->newLine();
        $this->warn('One-time link — it expires, it works once, and it is now in this terminal’s history:');
        $this->line(sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $token,
            urlencode($operator->email),
        ));
        $this->newLine();
    }
}
