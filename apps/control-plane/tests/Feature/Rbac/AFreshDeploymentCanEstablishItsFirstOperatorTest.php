<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bootstrap paradox, and the only place it is allowed to be solved.
 *
 * ---------------------------------------------------------------------------
 * The dead end
 * ---------------------------------------------------------------------------
 *
 * RolePermissionSeeder creates the roles and the permissions and assigns them
 * to nobody — correctly, because a seeder that creates a privileged account
 * creates it with a password somebody can read in Git. DevelopmentSeeder does
 * assign one, and refuses to run in production, also correctly.
 *
 * So a production deployment came up with every permission defined and no
 * principal holding any of them, and nothing in the platform could change
 * that: there is no user administration surface, `role.manage` is referenced
 * by no route, and no console command creates an operator. The only ways out
 * were a SQL client, a seeder edit or tinker — which is the defect.
 *
 * ---------------------------------------------------------------------------
 * What is allowed to be outside the platform, and what is not
 * ---------------------------------------------------------------------------
 *
 * Exactly one thing: the first privileged identity. An authenticated admin
 * screen cannot be the way to create the first person who may use an
 * authenticated admin screen. Everything after that — the second operator,
 * roles, permissions — belongs inside the platform and is proved elsewhere.
 *
 * The command sets no password. It issues the one-time reset link the
 * platform already implements, so there is no credential to print, commit,
 * default or leak, and the link expires and is single-use on its own terms.
 */
final class AFreshDeploymentCanEstablishItsFirstOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles and permissions only: exactly what a production deployment
        // runs. No accounts.
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_fresh_deployment_has_no_principal_that_can_administer_it(): void
    {
        // The positive control for every other test in this file: the dead end
        // is real before the bootstrap runs.
        $this->assertSame(0, User::query()->count());

        $this->assertSame(
            0,
            User::query()->role(Role::SuperAdmin->value)->count(),
            'Something already holds privileged administration; this suite would prove nothing.',
        );
    }

    #[Test]
    public function the_bootstrap_establishes_exactly_one_privileged_operator(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', [
            'email' => 'ops@lynomia.test',
            '--name' => 'First Operator',
        ])->assertSuccessful();

        /** @var User $operator */
        $operator = User::query()->where('email', 'ops@lynomia.test')->sole();

        $this->assertTrue($operator->hasRole(Role::SuperAdmin->value));
        $this->assertSame('First Operator', $operator->name);
        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());
    }

    #[Test]
    public function the_bootstrap_sets_no_password_anybody_could_know(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test'])->assertSuccessful();

        /** @var User $operator */
        $operator = User::query()->where('email', 'ops@lynomia.test')->sole();

        /*
         * Every password this repository has ever shipped in a fixture, and
         * the address they come with. None of them may open this account.
         */
        foreach (['password', 'secret', 'admin', 'changeme', 'lynomia', '12345678'] as $guess) {
            $this->assertFalse(
                password_verify($guess, (string) $operator->password),
                sprintf('The bootstrap set a guessable password (%s).', $guess),
            );
        }

        // And the account cannot be signed into at all until the operator has
        // been through the reset link.
        $this->postJson('/api/v1/login', [
            'email' => 'ops@lynomia.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_operator_takes_the_account_over_through_the_reset_link_and_can_sign_in(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test'])->assertSuccessful();

        /** @var User $operator */
        $operator = User::query()->where('email', 'ops@lynomia.test')->sole();

        // The link the platform already issues for a forgotten password, which
        // is why the bootstrap does not invent a second token mechanism.
        $token = Password::broker()->createToken($operator);

        $this->postJson('/api/v1/password/reset', [
            'token' => $token,
            'email' => 'ops@lynomia.test',
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSuccessful();

        $this->postJson('/api/v1/login', [
            'email' => 'ops@lynomia.test',
            'password' => 'Correct-Horse-Battery-9',
        ])->assertSuccessful();
    }

    #[Test]
    public function the_bootstrap_closes_behind_itself(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', ['email' => 'first@lynomia.test'])->assertSuccessful();

        // Second attempt, different address: the mechanism is not a way to
        // mint privileged accounts, it is a way to establish the first one.
        $this->artisan('operator:bootstrap', ['email' => 'second@lynomia.test'])->assertFailed();

        $this->assertSame(
            1,
            User::query()->role(Role::SuperAdmin->value)->count(),
            'The bootstrap minted a second privileged operator.',
        );

        $this->assertNull(
            User::query()->where('email', 'second@lynomia.test')->first(),
            'The refused bootstrap left an account behind.',
        );
    }

    #[Test]
    public function the_bootstrap_is_recorded(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test'])->assertSuccessful();

        $entry = AuditEntry::query()->where('action', AuditAction::OperatorBootstrapped)->sole();

        $this->assertSame('ops@lynomia.test', $entry->context['email'] ?? null);

        // The trail records that it happened, not anything that could be used.
        $body = json_encode($entry->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('token', strtolower($body));
        $this->assertStringNotContainsString('password', strtolower($body));
    }

    #[Test]
    public function the_first_operator_reaches_the_control_center(): void
    {
        Notification::fake();

        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test'])->assertSuccessful();

        /** @var User $operator */
        $operator = User::query()->where('email', 'ops@lynomia.test')->sole();

        // The super-admin bypass is the platform's chosen model, so the first
        // operator reaches an operator surface without anybody having granted
        // an individual permission.
        $this->actingAs($operator)
            ->getJson('/api/admin/infrastructure/overview')
            ->assertOk();
    }
}
