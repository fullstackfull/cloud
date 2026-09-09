<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\AssessProvider;
use Lynomia\Modules\Providers\Application\Actions\RefreshLicenceStates;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A licence's state comes from the calendar, and an operator can override it
 * in one direction only.
 *
 * The failure this is shaped around is the one a hosting company has
 * eventually: a panel stops serving because a licence lapsed, and the platform
 * kept selling onto it because nothing in the platform knew. So the sweep is
 * tested against time travel — a licence that is fine today is expiring in
 * twenty days and expired in forty, and each transition is audited and blocks
 * the provider under it — and the operator's one override is tested for
 * refusing to work in the other direction.
 */
final class ALicenceFollowsTheCalendarAndNotTheOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(User $operator, array $overrides = []): TestResponse
    {
        return $this->actingAs($operator)->postJson('/api/admin/licences', array_merge([
            'product' => 'cpanel',
            'licence_type' => 'admin',
            'environment' => DeploymentEnvironment::Staging->value,
            'starts_on' => CarbonImmutable::now()->subMonth()->toDateString(),
            'expires_on' => CarbonImmutable::now()->addYear()->toDateString(),
            'seats' => 100,
            'external_reference' => 'ORDER-1042',
        ], $overrides));
    }

    /**
     * A provider whose driver the catalogue says needs a licence.
     *
     * The first version of this used the fake driver, which needs none — and
     * the readiness engine, correctly, never blamed the licence. A lapsed
     * Proxmox subscription must not block a cluster; a lapsed cPanel licence
     * must block the panel. cPanel has no connection tester, so the blocker
     * after the licence one is always "cannot be proven", never null.
     */
    private function providerUnder(Licence $licence): ProviderInstance
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::DiscoveryOnly)
            ->create(['environment' => DeploymentEnvironment::Staging]);

        return ProviderInstance::factory()->create([
            'driver' => 'cpanel',
            'category' => ProviderCategory::Hosting,
            'endpoint' => 'https://panel.example.test:2087',
            'managed_server_id' => $server->getKey(),
            'credential_reference_id' => CredentialReference::factory()->proven()->create()->getKey(),
            'licence_id' => $licence->getKey(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Recording
     */

    #[Test]
    public function a_licence_in_force_records_as_active_with_its_days_remaining(): void
    {
        $response = $this->record($this->operator());

        $response->assertCreated();
        $response->assertJsonPath('data.state', LicenceState::Active->value);
        $response->assertJsonPath('data.permits', true);
        $response->assertJsonPath('data.needs_attention', false);
        $this->assertGreaterThan(300, $response->json('data.days_remaining'));
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LicenceRecorded->value]);
    }

    #[Test]
    public function a_licence_that_has_not_started_yet_is_pending_and_permits_nothing(): void
    {
        $response = $this->record($this->operator(), [
            'starts_on' => CarbonImmutable::now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.state', LicenceState::Pending->value);
        $response->assertJsonPath('data.permits', false);
    }

    #[Test]
    public function a_licence_recorded_already_expired_says_so_rather_than_being_refused(): void
    {
        // Recording an expired licence is how a migration of real inventory
        // begins, and it is the honest state.
        $response = $this->record($this->operator(), [
            'expires_on' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.state', LicenceState::Expired->value);
        $this->assertLessThan(0, $response->json('data.days_remaining'));
    }

    #[Test]
    public function a_licence_key_posted_under_its_own_name_is_refused_and_pointed_at_the_credential_centre(): void
    {
        foreach (['key', 'licence_key', 'license_key', 'secret'] as $field) {
            $response = $this->record($this->operator(), [$field => 'CPANEL-KEY-do-not-store-me']);

            $response->assertUnprocessable();
            $this->assertStringContainsString('credential reference', $response->getContent());
            $this->assertStringNotContainsString('do-not-store-me', $response->getContent());
        }
    }

    #[Test]
    public function a_licence_cannot_cover_a_machine_or_credential_in_another_environment(): void
    {
        $operator = $this->operator();
        $server = ManagedServer::factory()->create([
            'environment' => DeploymentEnvironment::Production,
        ]);

        $this->record($operator, ['managed_server_id' => $server->id])->assertConflict();

        $credential = CredentialReference::factory()->forEnvironment(DeploymentEnvironment::Development)->create();
        $this->record($operator, ['credential_id' => $credential->id])->assertConflict();
    }

    #[Test]
    public function the_list_puts_what_needs_a_person_first(): void
    {
        $operator = $this->operator();
        Licence::factory()->create(['product' => 'fine', 'expires_on' => CarbonImmutable::now()->addYear()]);
        Licence::factory()->in(LicenceState::Expired)->create(['product' => 'lapsed', 'expires_on' => CarbonImmutable::now()->subDay()]);
        Licence::factory()->in(LicenceState::Expiring)->create(['product' => 'soon', 'expires_on' => CarbonImmutable::now()->addWeek()]);

        $response = $this->actingAs($operator)->getJson('/api/admin/licences');

        $response->assertOk();
        $this->assertSame(['lapsed', 'soon', 'fine'], array_column($response->json('data'), 'product'));
    }

    /* ---------------------------------------------------------------------
     | The calendar
     */

    #[Test]
    public function the_sweep_moves_a_licence_through_expiring_to_expired_and_blocks_the_provider_at_each_step(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 08:00:00');

        $licence = Licence::factory()->create([
            'state' => LicenceState::Active,
            'expires_on' => '2026-02-15',
        ]);
        $provider = $this->providerUnder($licence);
        $refresh = app(RefreshLicenceStates::class);

        // Today: nothing to do.
        $this->assertSame(0, $refresh->execute()['changed']);
        $this->assertSame(LicenceState::Active, $licence->fresh()->state);

        // Twenty days later: within the thirty-day window.
        CarbonImmutable::setTestNow('2026-01-21 08:00:00');
        $outcome = $refresh->execute();

        $this->assertSame(1, $outcome['changed']);
        $this->assertSame(1, $outcome['providers_reassessed']);
        $this->assertSame(LicenceState::Expiring, $licence->fresh()->state);
        // Expiring still permits: the licence is not what blocks the provider
        // (cPanel has no tester yet, so something else still does).
        $this->assertNotSame(BlockerReason::Licence, $provider->fresh()->blocker);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LicenceStateChanged->value]);

        // The day after it lapses.
        CarbonImmutable::setTestNow('2026-02-16 08:00:00');
        $refresh->execute();

        $this->assertSame(LicenceState::Expired, $licence->fresh()->state);
        $this->assertSame(BlockerReason::Licence, $provider->fresh()->blocker);
        $this->assertSame(ReadinessState::NotReady, $provider->fresh()->readiness);
        $this->assertSame(ProviderState::Blocked, $provider->fresh()->state);
    }

    #[Test]
    public function the_sweep_never_touches_a_decision(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 08:00:00');

        $invalid = Licence::factory()->in(LicenceState::Invalid)->create(['expires_on' => '2027-01-01']);
        $notRequired = Licence::factory()->in(LicenceState::NotRequired)->create(['expires_on' => null]);

        app(RefreshLicenceStates::class)->execute();

        // Dates say Active; the decision says otherwise, and the decision wins.
        $this->assertSame(LicenceState::Invalid, $invalid->fresh()->state);
        $this->assertSame(LicenceState::NotRequired, $notRequired->fresh()->state);
    }

    #[Test]
    public function an_operator_can_run_the_sweep_and_it_is_the_same_sweep(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 08:00:00');
        Licence::factory()->create(['state' => LicenceState::Active, 'expires_on' => '2026-02-01']);

        $response = $this->actingAs($this->operator())->postJson('/api/admin/licences/refresh');

        $response->assertOk();
        $response->assertJsonPath('data.changed', 1);
    }

    #[Test]
    public function the_console_command_runs_the_same_sweep(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 08:00:00');
        Licence::factory()->create(['state' => LicenceState::Active, 'expires_on' => '2026-02-01']);

        $this->artisan('licences:refresh')
            ->expectsOutputToContain('1 changed state')
            ->assertSuccessful();
    }

    /* ---------------------------------------------------------------------
     | The operator's one override
     */

    #[Test]
    public function marking_a_licence_invalid_blocks_every_provider_under_it_with_the_reason(): void
    {
        $operator = $this->operator();
        $licence = Licence::factory()->create();
        $provider = $this->providerUnder($licence);

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/licences/{$licence->id}/invalidate", ['reason' => 'Vendor says the order was refunded.']);

        $response->assertOk();
        $response->assertJsonPath('data.state', LicenceState::Invalid->value);
        $response->assertJsonPath('data.permits', false);
        $response->assertJsonPath('data.invalidated_reason', 'Vendor says the order was refunded.');

        $this->assertSame(BlockerReason::Licence, $provider->fresh()->blocker);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LicenceInvalidated->value]);
    }

    #[Test]
    public function nothing_an_operator_types_makes_an_expired_licence_active(): void
    {
        /*
         * There is no endpoint that sets the state, and this proves that the
         * ones that exist cannot be bent into one: a renewal that is itself
         * expired is refused, and the invalidate endpoint only goes one way.
         */
        $operator = $this->operator();
        $licence = Licence::factory()->in(LicenceState::Expired)->create(['expires_on' => CarbonImmutable::now()->subDay()]);

        $this->actingAs($operator)
            ->postJson("/api/admin/licences/{$licence->id}/renew", ['expires_on' => CarbonImmutable::now()->subDay()->toDateString()])
            ->assertConflict();

        $this->assertSame(LicenceState::Expired, $licence->fresh()->state);
    }

    #[Test]
    public function a_renewal_clears_an_invalidation_and_unblocks_the_provider_at_once(): void
    {
        $operator = $this->operator();
        $licence = Licence::factory()->in(LicenceState::Invalid)->create([
            'invalidated_reason' => 'Refunded.',
            'expires_on' => CarbonImmutable::now()->subDay(),
        ]);
        $provider = $this->providerUnder($licence);
        app(AssessProvider::class)->execute($provider->load(['server', 'credential', 'licence', 'capabilities']));
        $this->assertSame(BlockerReason::Licence, $provider->fresh()->blocker);

        $response = $this->actingAs($operator)->postJson("/api/admin/licences/{$licence->id}/renew", [
            'expires_on' => CarbonImmutable::now()->addYear()->toDateString(),
            'external_reference' => 'ORDER-2001',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.state', LicenceState::Active->value);
        $response->assertJsonPath('data.invalidated_reason', null);
        $response->assertJsonPath('data.external_reference', 'ORDER-2001');

        // Past the licence: the next blocker in dependency order, not this one.
        $this->assertNotSame(BlockerReason::Licence, $provider->fresh()->blocker);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LicenceRenewed->value]);
    }

    /* ---------------------------------------------------------------------
     | Attaching
     */

    #[Test]
    public function attaching_a_licence_moves_a_provider_past_the_licence_blocker(): void
    {
        $operator = $this->operator();
        $provider = ProviderInstance::factory()->create([
            'driver' => 'cpanel',
            'category' => ProviderCategory::Hosting,
            'endpoint' => 'https://panel.example.test:2087',
            'managed_server_id' => ManagedServer::factory()->classified(SafetyClass::DiscoveryOnly)->create(['environment' => DeploymentEnvironment::Staging])->getKey(),
        ]);

        $this->actingAs($operator)->postJson("/api/admin/providers/{$provider->id}/assess")
            ->assertJsonPath('data.readiness.blocker', BlockerReason::Licence->value);

        $licence = Licence::factory()->create();

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/licence", ['licence_id' => $licence->id]);

        $response->assertOk();
        $response->assertJsonPath('data.licence.product', 'cpanel');
        // The next blocker in dependency order, not the same one.
        $response->assertJsonPath('data.readiness.blocker', BlockerReason::Credentials->value);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::LicenceAttached->value]);
    }

    #[Test]
    public function an_invalid_or_foreign_environment_licence_cannot_be_attached(): void
    {
        $operator = $this->operator();
        $provider = ProviderInstance::factory()->create();

        $invalid = Licence::factory()->in(LicenceState::Invalid)->create();
        $this->actingAs($operator)->postJson("/api/admin/providers/{$provider->id}/licence", ['licence_id' => $invalid->id])->assertConflict();

        $foreign = Licence::factory()->forEnvironment(DeploymentEnvironment::Production)->create();
        $this->actingAs($operator)->postJson("/api/admin/providers/{$provider->id}/licence", ['licence_id' => $foreign->id])->assertConflict();

        $this->assertNull($provider->fresh()->licence_id);
    }

    /* ---------------------------------------------------------------------
     | Who may
     */

    #[Test]
    public function reading_the_estate_does_not_mean_managing_its_licences(): void
    {
        $noc = $this->operator(Role::Noc);
        $licence = Licence::factory()->create();

        $this->actingAs($noc)->getJson('/api/admin/licences')->assertOk();
        $this->actingAs($noc)->postJson('/api/admin/licences', [])->assertForbidden();
        $this->actingAs($noc)->postJson("/api/admin/licences/{$licence->id}/invalidate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($noc)->postJson('/api/admin/licences/refresh')->assertForbidden();

        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/admin/licences')->assertForbidden();
    }
}
