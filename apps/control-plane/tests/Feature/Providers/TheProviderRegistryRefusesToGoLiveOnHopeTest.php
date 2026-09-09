<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A provider becomes live because somebody proved it works, or it does not
 * become live.
 *
 * ---------------------------------------------------------------------------
 * The failure this is shaped around
 * ---------------------------------------------------------------------------
 *
 * Enabling a provider is the point where a configuration screen turns into
 * customers' money and machines. Every previous phase of this platform has
 * produced at least one capability that looked complete in code and could not
 * complete in the product, and the shape was always the same: something was
 * treated as done because the parts were present rather than because the whole
 * had been exercised.
 *
 * So the cases here are mostly refusals, and the successful path is the one
 * that had to walk through registration, a credential, a real connection test
 * and capability discovery to get there.
 */
final class TheProviderRegistryRefusesToGoLiveOnHopeTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET_VARIABLE = 'LYNOMIA_TEST_PROVIDER_SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // The fake tester refuses a target with no secret, exactly as a real
        // one would. Supplying it through the process environment rather than
        // through a config value is what the deployment controller does.
        putenv(self::SECRET_VARIABLE.'=not-a-real-secret');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET_VARIABLE);

        parent::tearDown();
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function credential(
        CredentialState $state = CredentialState::Configured,
        DeploymentEnvironment $environment = DeploymentEnvironment::Staging,
    ): CredentialReference {
        return CredentialReference::factory()->create([
            'state' => $state,
            'environment' => $environment,
            'backend_reference' => self::SECRET_VARIABLE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function register(User $operator, array $overrides = []): TestResponse
    {
        return $this->actingAs($operator)->postJson('/api/admin/providers', array_merge([
            'name' => 'dns-'.uniqid(),
            'driver' => 'fake',
            'category' => ProviderCategory::Dns->value,
            'environment' => DeploymentEnvironment::Staging->value,
            'endpoint' => 'fake://connected',
        ], $overrides));
    }

    /* ---------------------------------------------------------------------
     | Registration
     */

    #[Test]
    public function a_driver_the_platform_has_no_adapter_for_is_refused(): void
    {
        $response = $this->register($this->operator(), ['driver' => 'aws']);

        $response->assertConflict();
        $response->assertJsonPath('error.code', 'provider_refused');
        $this->assertStringContainsString('no aws adapter', $response->json('error.message'));

        // Nothing stored. A row naming a driver nothing implements is a
        // provider that can never be reached and can never be explained.
        $this->assertDatabaseCount('provider_instances', 0);
    }

    #[Test]
    public function a_category_that_disagrees_with_the_adapter_is_refused(): void
    {
        $response = $this->register($this->operator(), ['category' => ProviderCategory::Payment->value]);

        $response->assertConflict();
        $this->assertStringContainsString('not a payment one', $response->json('error.message'));
    }

    #[Test]
    public function a_provider_that_runs_on_our_hardware_needs_a_machine_named(): void
    {
        $response = $this->register($this->operator(), [
            'driver' => 'proxmox',
            'category' => ProviderCategory::Compute->value,
        ]);

        $response->assertConflict();
        $this->assertStringContainsString('machine we manage', $response->json('error.message'));
    }

    #[Test]
    public function a_machine_in_another_environment_cannot_host_this_provider(): void
    {
        $server = ManagedServer::factory()->create([
            'environment' => DeploymentEnvironment::Development,
            'safety_class' => SafetyClass::ConfigurationAllowed,
        ]);

        $response = $this->register($this->operator(), [
            'driver' => 'proxmox',
            'category' => ProviderCategory::Compute->value,
            'environment' => DeploymentEnvironment::Staging->value,
            'managed_server_id' => $server->id,
        ]);

        $response->assertConflict();
        $this->assertStringContainsString('development machine', $response->json('error.message'));
    }

    #[Test]
    public function a_newly_registered_provider_reports_a_complete_state_in_the_create_response(): void
    {
        /*
         * The regression this platform has now paid for twice. The row a
         * caller renders is the object create() returned, not a reload, and a
         * state left to a column default is null on that object.
         */
        $response = $this->register($this->operator());

        $response->assertCreated();
        $response->assertJsonPath('data.state', ProviderState::Blocked->value);
        $response->assertJsonPath('data.connection.state', ConnectionState::NotTested->value);
        $this->assertNotNull($response->json('data.readiness.state'));
        $this->assertNotNull($response->json('data.readiness.blocker'));
    }

    #[Test]
    public function registration_says_what_is_missing_rather_than_only_that_something_is(): void
    {
        // Registered with no credential, so the first actionable step is a
        // credential — and the response names it without anybody asking.
        $response = $this->register($this->operator());

        $response->assertJsonPath('data.readiness.blocker', 'blocked_credentials');
        $response->assertJsonPath('data.readiness.next_action', 'controlCenter.guidance.credentials');
    }

    #[Test]
    public function registration_is_recorded(): void
    {
        $this->register($this->operator())->assertCreated();

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProviderRegistered->value]);
    }

    /* ---------------------------------------------------------------------
     | Enabling
     */

    private function readyProvider(): ProviderInstance
    {
        $provider = ProviderInstance::factory()->create([
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'readiness' => ReadinessState::ReadyForProduction,
            'state' => ProviderState::Ready,
            'credential_reference_id' => $this->credential(CredentialState::Valid)->getKey(),
        ]);

        ProviderCapability::factory()->for($provider, 'provider')->create();

        return $provider;
    }

    #[Test]
    public function a_provider_nobody_has_contacted_cannot_be_enabled(): void
    {
        $provider = ProviderInstance::factory()->create([
            'endpoint' => 'fake://connected',
            'credential_reference_id' => $this->credential(CredentialState::Valid)->getKey(),
            // The lie this test exists to catch: the stored readiness says
            // ready, and nothing has ever answered.
            'readiness' => ReadinessState::ReadyForProduction,
            'state' => ProviderState::Ready,
        ]);

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$provider->id}/enable");

        $response->assertConflict();
        $this->assertSame(ProviderState::Blocked, $provider->fresh()->state);
    }

    #[Test]
    public function the_stored_readiness_is_not_trusted_at_the_moment_of_enabling(): void
    {
        /*
         * The race that matters. An operator loads a screen showing a ready
         * provider; between that and the button, somebody revokes the
         * credential. Enabling reassesses inside the transaction rather than
         * reading the column written when the screen was drawn.
         */
        $provider = $this->readyProvider();

        $provider->credential->forceFill(['state' => CredentialState::Revoked])->save();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$provider->id}/enable");

        $response->assertConflict();
        $this->assertStringContainsString('revoked', $response->json('error.message'));
        $this->assertSame(ProviderState::Blocked, $provider->fresh()->state);
    }

    #[Test]
    public function a_proven_provider_can_be_enabled_and_is_recorded(): void
    {
        $provider = $this->readyProvider();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$provider->id}/enable");

        $response->assertOk();
        $response->assertJsonPath('data.state', ProviderState::Enabled->value);
        $response->assertJsonPath('data.is_serving', true);

        $this->assertNotNull($provider->fresh()->enabled_at);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProviderEnabled->value]);
    }

    #[Test]
    public function a_second_provider_of_the_same_kind_cannot_also_be_enabled(): void
    {
        /*
         * Two enabled registrars in production is not a configuration somebody
         * meant: it is two answers to "where does this registration go", and
         * the platform would pick one arbitrarily. Enforced by a partial
         * unique index, so it holds even for two requests that never see each
         * other's row.
         */
        $this->actingAs($this->operator())
            ->postJson('/api/admin/providers/'.$this->readyProvider()->id.'/enable')
            ->assertOk();

        $second = $this->readyProvider();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$second->id}/enable");

        $response->assertConflict();
        $this->assertStringContainsString('already enabled', $response->json('error.message'));

        // Still Ready, not Blocked. Nothing is wrong with this provider — the
        // slot is taken — and marking it blocked would send an operator
        // looking for a fault that does not exist.
        $this->assertSame(ProviderState::Ready, $second->fresh()->state);
    }

    #[Test]
    public function the_same_kind_of_provider_may_be_enabled_in_a_different_environment(): void
    {
        // The index is per environment on purpose. Staging and production each
        // need their own registrar, and one blocking the other would make it
        // impossible to rehearse anything.
        $staging = $this->readyProvider();

        $production = ProviderInstance::factory()->inProduction()->create([
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'credential_reference_id' => $this->credential(CredentialState::Valid, DeploymentEnvironment::Production)->getKey(),
        ]);
        ProviderCapability::factory()->for($production, 'provider')->create();

        $this->actingAs($this->operator())->postJson("/api/admin/providers/{$staging->id}/enable")->assertOk();
        $this->actingAs($this->operator())->postJson("/api/admin/providers/{$production->id}/enable")->assertOk();
    }

    #[Test]
    public function the_whole_path_from_registration_to_live_works_end_to_end(): void
    {
        /*
         * ------------------------------------------------------------------
         * The test the rest of this file exists to make trustworthy
         * ------------------------------------------------------------------
         *
         * Everything else here asserts a refusal, and a suite of refusals can
         * all pass on a system where nothing succeeds. This walks the path an
         * operator actually walks — register, attach a credential, contact the
         * provider, find out what it can do, switch it on — through the HTTP
         * surface, and checks the state moves at each step for the reason that
         * step exists.
         *
         * Every step goes through the HTTP surface an operator would use.
         */
        $operator = $this->operator();

        // 1. Registered. Nothing has been contacted, and it says so.
        $registered = $this->register($operator)->assertCreated();
        $provider = ProviderInstance::query()->findOrFail($registered->json('data.id'));

        $this->assertSame(ConnectionState::NotTested, $provider->connection_state);
        $this->assertSame(ReadinessState::NotReady, $provider->readiness);

        // 2. A credential is attached through the credential centre, and is
        //    merely configured — nobody has used it yet, so this must not be
        //    enough to serve. The attachment itself already says so.
        $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/credential", [
                'credential_id' => $this->credential(CredentialState::Configured)->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.readiness.blocker', 'blocked_credentials');

        $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/enable")
            ->assertConflict();

        // 3. Contacted. The test proves the credential and discovers what the
        //    account can do, in one round trip — which is the point of asking.
        $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/connection-test")
            ->assertOk()
            ->assertJsonPath('data.reached', true)
            ->assertJsonPath('data.usable', true);

        $provider->refresh();

        $this->assertSame(ConnectionState::Connected, $provider->connection_state);
        $this->assertSame(
            CredentialState::Valid,
            $provider->credential->fresh()->state,
            'A successful test is what turns a configured credential into a proven one.',
        );
        $this->assertNotEmpty(
            $provider->capabilities()->get(),
            'Discovery happens as part of the test; a provider that answers and was never asked what it can do is not ready.',
        );

        // 4. Readiness moved on its own, because the test wrote through it.
        $this->assertSame(ReadinessState::ReadyForProduction, $provider->readiness);
        $this->assertNull($provider->blocker);

        // 5. And only now can it be switched on.
        $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.state', ProviderState::Enabled->value)
            ->assertJsonPath('data.is_serving', true);

        // Every step of that is on the record, in order.
        foreach ([AuditAction::ProviderRegistered, AuditAction::ConnectionTested, AuditAction::ProviderEnabled] as $action) {
            $this->assertDatabaseHas('audit_log', ['action' => $action->value]);
        }
    }

    /* ---------------------------------------------------------------------
     | Disabling
     */

    #[Test]
    public function switching_a_provider_off_needs_a_reason(): void
    {
        $provider = $this->readyProvider();

        $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$provider->id}/disable", [])
            ->assertStatus(422);
    }

    #[Test]
    public function switching_a_provider_off_stops_new_work_and_touches_nothing_else(): void
    {
        /*
         * The restraint that is the feature. A customer whose service runs
         * behind this provider still owns it, and the obvious "tidy up"
         * version of disabling — deactivating what the provider serves — is
         * how a misclick becomes an outage for people who are paying.
         */
        $provider = $this->readyProvider();
        $this->actingAs($this->operator())->postJson("/api/admin/providers/{$provider->id}/enable")->assertOk();

        $server = ManagedServer::factory()->create(['safety_class' => SafetyClass::ConfigurationAllowed]);
        $capabilitiesBefore = $provider->capabilities()->count();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$provider->id}/disable", ['reason' => 'Migrating to the new account.']);

        $response->assertOk();
        $response->assertJsonPath('data.state', ProviderState::Disabled->value);
        $response->assertJsonPath('data.is_serving', false);

        // Nothing it serves was touched: the machine is untouched, the
        // credential still exists and still says what it said, and the
        // discovered capabilities are still on record.
        $this->assertSame(SafetyClass::ConfigurationAllowed, $server->fresh()->safety_class);
        $this->assertSame(CredentialState::Valid, $provider->credential->fresh()->state);
        $this->assertSame($capabilitiesBefore, $provider->capabilities()->count());

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProviderDisabled->value]);
    }

    #[Test]
    public function disabling_frees_the_slot_for_another_provider_of_the_same_kind(): void
    {
        $first = $this->readyProvider();
        $this->actingAs($this->operator())->postJson("/api/admin/providers/{$first->id}/enable")->assertOk();

        $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$first->id}/disable", ['reason' => 'Replaced.'])
            ->assertOk();

        $second = $this->readyProvider();

        $this->actingAs($this->operator())
            ->postJson("/api/admin/providers/{$second->id}/enable")
            ->assertOk();
    }

    /* ---------------------------------------------------------------------
     | What the surface does and does not say
     */

    #[Test]
    public function no_provider_response_carries_anything_that_could_authenticate_as_us(): void
    {
        $provider = $this->readyProvider();

        $body = $this->actingAs($this->operator())
            ->getJson("/api/admin/providers/{$provider->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('backend_reference', $body);
        $this->assertStringNotContainsString(self::SECRET_VARIABLE, $body);
        $this->assertStringNotContainsString('not-a-real-secret', $body);

        // The name is there on purpose: an operator has to be able to tell
        // which credential is attached without being shown anything.
        $this->assertStringContainsString($provider->credential->name, $body);
    }

    #[Test]
    public function the_catalogue_says_which_drivers_can_actually_be_proven(): void
    {
        $response = $this->actingAs($this->operator())->getJson('/api/admin/providers/catalogue');

        $response->assertOk();

        $byDriver = collect($response->json('data'))->keyBy('driver');

        // The honest state of this build: adapters exist for these, and no
        // connection tester does.
        $this->assertFalse($byDriver['proxmox']['testable']);
        $this->assertFalse($byDriver['cloudflare']['testable']);
        $this->assertTrue($byDriver['fake']['testable']);

        // And the requirements are data, so a registration form can ask for
        // the right things instead of the operator learning from a refusal.
        $this->assertTrue($byDriver['proxmox']['needs_server']);
        $this->assertFalse($byDriver['cloudflare']['needs_server']);
        $this->assertTrue($byDriver['cpanel']['needs_licence']);
    }

    #[Test]
    public function a_customer_cannot_reach_any_of_this(): void
    {
        $customer = User::factory()->create();
        $provider = $this->readyProvider();

        $this->actingAs($customer)->getJson('/api/admin/providers')->assertForbidden();
        $this->actingAs($customer)->getJson('/api/admin/providers/catalogue')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/admin/providers')->assertForbidden();
        $this->actingAs($customer)->postJson("/api/admin/providers/{$provider->id}/enable")->assertForbidden();
        $this->actingAs($customer)->postJson("/api/admin/providers/{$provider->id}/disable")->assertForbidden();
    }

    #[Test]
    public function an_operator_who_may_read_the_estate_cannot_enable_a_provider(): void
    {
        // Support agents legitimately need to see what is blocked. Deciding
        // that a provider may take real work is a different job.
        $agent = $this->operator(Role::Support);
        $provider = $this->readyProvider();

        $this->actingAs($agent)->postJson("/api/admin/providers/{$provider->id}/enable")->assertForbidden();
    }
}
