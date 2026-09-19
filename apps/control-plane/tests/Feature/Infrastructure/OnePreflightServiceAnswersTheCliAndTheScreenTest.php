<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Infrastructure\Models\ConnectionTest;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CLI and the Control Center are two presentations of one answer.
 *
 * ===========================================================================
 * WHY THIS IS ASSERTED RATHER THAN ARRANGED
 * ===========================================================================
 *
 * Two callers with the same question drift. The screen grows a rule the
 * command does not have, or the command gains a check the screen never shows,
 * and six months later an operator is told two different things about the same
 * provider by the same platform. The only durable fix is one implementation,
 * and the only way to keep it one is to compare the two outputs in a test.
 *
 * So the JSON the command prints and the JSON the endpoint returns are
 * compared field for field, minus the timestamps — which differ because they
 * are two runs, and which differing is itself correct: a preflight an operator
 * asks for always runs fresh.
 */
final class OnePreflightServiceAnswersTheCliAndTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_PREFLIGHT_API_SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        putenv(self::VARIABLE.'=never-reported-anywhere');
    }

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);

        parent::tearDown();
    }

    /* =====================================================================
     | One answer, two presentations
     ===================================================================== */

    #[Test]
    public function the_command_and_the_endpoint_report_the_same_thing(): void
    {
        $this->provider();

        $cli = json_decode(
            $this->lastCommandOutput('infra:preflight', ['--mode' => 'simulation', '--json' => true]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $api = $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk()
            ->json('data');

        foreach (['mode', 'mode_label', 'scope', 'overall_status', 'passed', 'blocker_reasons', 'verification_levels', 'real_verification_claims', 'next_actions'] as $field) {
            $this->assertSame($cli[$field], $api[$field], sprintf(
                'The command and the endpoint disagree about "%s". They must be two presentations of one answer, not two answers.',
                $field,
            ));
        }

        $this->assertSame(
            array_map(static fn (array $check): string => $check['id'], $cli['checks']),
            array_map(static fn (array $check): string => $check['id'], $api['checks']),
        );
    }

    /* =====================================================================
     | It writes nothing
     ===================================================================== */

    #[Test]
    public function a_preflight_through_the_api_changes_nothing_except_its_own_audit_entry(): void
    {
        $provider = $this->provider();

        $before = [
            'provider' => $provider->only(['connection_state', 'connection_detail', 'last_connection_test_at', 'readiness', 'state', 'blocker']),
            'credential' => $provider->credential?->only(['state', 'last_tested_at']),
            'connection_tests' => ConnectionTest::query()->count(),
            'capabilities' => DB::table('provider_capabilities')->count(),
            'readiness_rows' => DB::table('product_readiness')->count(),
        ];

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk();

        $after = [
            'provider' => $provider->fresh()?->only(['connection_state', 'connection_detail', 'last_connection_test_at', 'readiness', 'state', 'blocker']),
            'credential' => $provider->credential?->fresh()?->only(['state', 'last_tested_at']),
            'connection_tests' => ConnectionTest::query()->count(),
            'capabilities' => DB::table('provider_capabilities')->count(),
            'readiness_rows' => DB::table('product_readiness')->count(),
        ];

        /*
         * Every one of these would move if the preflight used the connection
         * test action instead of the read-only probe, and that is exactly the
         * mistake the extraction exists to prevent. A diagnosis that marked
         * this credential Invalid would have changed the answer it was asked
         * about.
         */
        $this->assertSame($before, $after, 'A preflight moved the platform\'s own state. It observes.');
    }

    #[Test]
    public function the_run_itself_is_recorded_with_no_secret_and_no_provider_detail(): void
    {
        $this->provider();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk();

        $entries = DB::table('audit_log')
            ->where('action', AuditAction::InfrastructurePreflightRun->value)
            ->get();

        $this->assertCount(1, $entries, 'A run that leaves no trace at all is a run nobody can account for afterwards.');

        $context = (string) $entries->first()?->context;

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($context, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('simulation', $decoded['mode']);
        $this->assertSame('estate', $decoded['scope']);
        $this->assertArrayHasKey('blocker_reasons', $decoded);
        $this->assertArrayHasKey('real_verification_claims', $decoded);

        // Who asked, in which mode, about what, and what came back. Nothing
        // else: no secret, no credential variable, no provider response.
        $this->assertStringNotContainsString('never-reported-anywhere', $context);
        $this->assertStringNotContainsString(self::VARIABLE, $context);
    }

    /* =====================================================================
     | Permissions
     ===================================================================== */

    #[Test]
    public function a_customer_cannot_run_a_preflight(): void
    {
        // A user with no operator role, which is what a customer is on this
        // surface: the admin API is reached by permission, not by audience.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_caller_cannot_run_a_preflight(): void
    {
        $this->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertUnauthorized();
    }

    #[Test]
    public function reading_the_estate_is_not_permission_to_dial_it(): void
    {
        /*
         * The mode-dependent half of the permission, and the reason it lives
         * in the controller rather than in middleware: middleware does not see
         * the body.
         *
         * A simulation run reads this platform's own records. A read-only-real
         * run sends real credentials to real endpoints, which is the same act
         * as pressing "test connection" — so it takes the same permission. And
         * it still confers nothing: the service cannot write, whoever calls it.
         */
        /*
         * Granted the one permission directly rather than through a seeded
         * role, so the assertion is about the split itself and cannot be
         * skipped into meaninglessness by a role gaining a permission later.
         */
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::InfrastructureView->value);
        $viewer = $viewer->fresh() ?? $viewer;

        $this->assertTrue($viewer->can(Permission::InfrastructureView->value));
        $this->assertFalse($viewer->can(Permission::ProviderManage->value));

        $this->actingAs($viewer)
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk();

        $this->actingAs($viewer)
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'read_only_real', 'scope' => 'estate'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    /* =====================================================================
     | The contract
     ===================================================================== */

    #[Test]
    public function the_mode_is_required_and_has_no_default(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['scope' => 'estate'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.fields.mode.0', 'The mode field is required.');
    }

    #[Test]
    public function a_scope_that_needs_a_target_is_refused_without_one(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'provider'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_scope');
    }

    #[Test]
    public function the_response_carries_no_secret_and_no_credential_reference(): void
    {
        $this->provider();

        $body = $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('never-reported-anywhere', (string) $body);
        $this->assertStringNotContainsString(self::VARIABLE, (string) $body);
        $this->assertStringNotContainsString('backend_reference', (string) $body);
    }

    #[Test]
    public function a_simulation_response_says_simulation(): void
    {
        $this->provider();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/preflight', ['mode' => 'simulation', 'scope' => 'estate'])
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'SIMULATION')
            ->assertJsonPath('data.real_verification_claims', []);
    }

    /* =====================================================================
     | The CLI's own contract
     ===================================================================== */

    #[Test]
    public function the_command_refuses_to_guess_a_mode(): void
    {
        $this->artisan('infra:preflight')->assertExitCode(2);
    }

    #[Test]
    public function the_command_refuses_two_scopes_at_once(): void
    {
        $this->artisan('infra:preflight', ['--mode' => 'simulation', '--provider' => 'a', '--product' => 'vps'])
            ->assertExitCode(2);
    }

    #[Test]
    public function the_command_exits_zero_when_nothing_blocks(): void
    {
        /*
         * The positive twin of every exit-code assertion in this file. Without
         * it, a command that returned 1 unconditionally would pass all of them
         * — and an operator whose pipeline never goes green stops trusting the
         * gate rather than fixing the estate.
         *
         * A machine classified for discovery, with a controlled BMC that
         * answers and a credential that resolves: every check in the machine
         * scope passes, so the report passes, so the command exits 0.
         */
        $server = ManagedServer::factory()
            ->withBmc()
            ->classified(SafetyClass::DiscoveryOnly)
            ->create([
                'credential_reference_id' => CredentialReference::factory()
                    ->create(['backend_reference' => self::VARIABLE])
                    ->getKey(),
            ]);

        $this->artisan('infra:preflight', ['--mode' => 'simulation', '--machine' => $server->name])
            ->assertExitCode(0);
    }

    #[Test]
    public function the_command_names_the_mode_before_anything_that_could_be_read_as_a_result(): void
    {
        $this->provider();

        $output = $this->lastCommandOutput('infra:preflight', ['--mode' => 'simulation']);

        $this->assertStringContainsString('SIMULATION', $output);

        $this->assertLessThan(
            strpos($output, '[') === false ? PHP_INT_MAX : (int) strpos($output, '['),
            (int) strpos($output, 'SIMULATION'),
            'The mode must appear before the first check line. A simulation header that scrolled off is a simulation '
            .'report somebody quotes as proof.',
        );

        $this->assertStringContainsString('Real infrastructure verified: NONE', $output);
    }

    /* =====================================================================
     | Helpers
     ===================================================================== */

    private function provider(): ProviderInstance
    {
        return ProviderInstance::factory()->create([
            'credential_reference_id' => CredentialReference::factory()
                ->create(['backend_reference' => self::VARIABLE])
                ->getKey(),
        ]);
    }

    private function operator(): User
    {
        return $this->operatorWith(Role::SuperAdmin);
    }

    private function operatorWith(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user->fresh() ?? $user;
    }

    /**
     * Run the command and hand back what it printed.
     *
     * `Artisan::call` rather than the test helper, because the helper's
     * assertions consume the output buffer and this needs the text itself.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function lastCommandOutput(string $command, array $arguments): string
    {
        Artisan::call($command, $arguments);

        return Artisan::output();
    }
}
