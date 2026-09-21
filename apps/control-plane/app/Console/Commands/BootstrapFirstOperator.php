<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
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
 */
final class BootstrapFirstOperator extends Command
{
    protected $signature = 'operator:bootstrap
        {email : The address the first operator will sign in with}
        {--name= : Their name, as it should appear in the audit trail}
        {--show-link : Print the one-time link instead of mailing it}';

    protected $description = 'Establish the first privileged operator on a fresh deployment.';

    public function handle(RecordActAtomically $record): int
    {
        $existing = User::query()->role(Role::SuperAdmin->value)->count();

        if ($existing > 0) {
            $this->error(sprintf(
                'This deployment already has %d privileged operator(s). Create further operators through '
                .'the Control Center, not from the console.',
                $existing,
            ));

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?? '')) ?: 'Platform Operator';

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $operator = $record->execute(
            act: function () use ($email, $name): User {
                /** @var User $user */
                $user = User::query()->firstOrNew(['email' => $email]);

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
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'password_changed_at' => null,
                ])->save();

                $user->assignRole(Role::SuperAdmin->value);

                return $user;
            },
            describe: static fn (User $user): AuditedAct => new AuditedAct(
                action: AuditAction::OperatorBootstrapped,
                subject: $user,
                // The act, and nothing that could be used: no token, no link,
                // no secret.
                context: ['email' => $user->email, 'name' => $user->name, 'role' => Role::SuperAdmin->value],
            ),
        );

        $this->deliverTheOneTimeLink($operator);

        $this->info(sprintf('%s is now the first operator of this deployment.', $operator->email));
        $this->line('Every operator after this one is created in the Control Center. This command will now refuse.');

        return self::SUCCESS;
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
