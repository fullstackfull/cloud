<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\FactSource;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The driver that reaches a machine is the one bound to it, never one a
 * request names — and a look at a machine is a look, not a touch.
 *
 * ---------------------------------------------------------------------------
 * Why the driver is not a parameter
 * ---------------------------------------------------------------------------
 *
 * A caller that could name the driver could point a test, and the credential
 * resolved for it, at any adapter the platform has. The first version of this
 * endpoint took `driver` from the request body with a default of "fake", and
 * that was two defects in one line: an operator could choose the adapter, and
 * the default would have thrown in production. The driver is now a fact about
 * the BMC provider registered against the machine, and a machine with none
 * bound is refused before any adapter is chosen.
 */
final class AMachineIsReachedThroughItsOwnBmcTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'LYNOMIA_TEST_BMC_SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        putenv(self::SECRET.'=not-a-real-secret');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET);

        parent::tearDown();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    private function reachable(SafetyClass $class = SafetyClass::DiscoveryOnly, bool $withBmc = true): ManagedServer
    {
        $factory = ManagedServer::factory()->classified($class);

        if ($withBmc) {
            $factory = $factory->withBmc();
        }

        return $factory->create([
            'credential_reference_id' => CredentialReference::factory()->create([
                'state' => CredentialState::Configured,
                'backend_reference' => self::SECRET,
            ])->getKey(),
        ]);
    }

    #[Test]
    public function a_machine_with_no_bmc_bound_cannot_be_tested_and_is_told_what_to_register(): void
    {
        $server = $this->reachable(withBmc: false);

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/connection-test");

        $response->assertConflict();
        $response->assertJsonPath('error.code', 'bmc_missing');
        $this->assertStringContainsString('Register one', $response->json('error.message'));

        // Nothing was tried, so nothing is recorded as tried.
        $this->assertSame(ConnectionState::NotTested, $server->fresh()->connection_state);
        $this->assertDatabaseCount('connection_tests', 0);
    }

    #[Test]
    public function the_driver_named_in_the_request_is_ignored(): void
    {
        $server = $this->reachable();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/connection-test", ['driver' => 'proxmox']);

        $response->assertOk();
        $response->assertJsonPath('data.result', ConnectionState::Connected->value);

        // The audit row names the driver that actually ran, which is the one
        // bound to the machine and not the one the caller asked for.
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ConnectionTested->value]);
        $entry = DB::table('audit_log')->where('action', AuditAction::ConnectionTested->value)->latest('id')->first();
        $context = json_decode((string) $entry->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('fake_bmc', $context['driver']);
        $this->assertStringNotContainsString('proxmox', (string) $entry->context);
    }

    #[Test]
    public function a_bmc_bound_to_one_machine_does_not_reach_another(): void
    {
        $withBmc = $this->reachable();
        $without = $this->reachable(withBmc: false);

        // The BMC provider exists in the database. It is bound to the other machine.
        $this->assertSame(1, ProviderInstance::query()->where('category', ProviderCategory::Bmc->value)->count());

        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$without->id}/connection-test")
            ->assertConflict()
            ->assertJsonPath('error.code', 'bmc_missing');

        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$withBmc->id}/connection-test")
            ->assertOk();
    }

    /* ---------------------------------------------------------------------
     | Discovery
     */

    #[Test]
    public function discovery_writes_what_the_machine_said_and_records_that_it_looked(): void
    {
        $server = $this->reachable();

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/discover");

        $response->assertOk();
        $response->assertJsonPath('data.usable', true);
        $this->assertGreaterThan(5, $response->json('data.facts'));

        $facts = $this->actingAs($this->operator())
            ->getJson("/api/admin/infrastructure/servers/{$server->id}/facts")
            ->assertOk()
            ->json('data');

        $byKey = collect($facts)->keyBy('key');
        $this->assertSame('Fabrikam', $byKey['vendor']['value']);
        $this->assertSame(FactSource::Discovered->value, $byKey['vendor']['source']);
        $this->assertNull($byKey['vendor']['superseded_at']);

        $this->assertNotNull($server->fresh()->last_discovery_at);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ServerDiscovered->value]);
    }

    #[Test]
    public function a_second_look_supersedes_what_changed_and_keeps_what_it_replaced(): void
    {
        $server = $this->reachable();
        $operator = $this->operator();

        $this->actingAs($operator)->postJson("/api/admin/infrastructure/servers/{$server->id}/discover")->assertOk();

        // Between the two looks, somebody swapped a disk. The fake always
        // reports the same inventory, so the change is planted on the row
        // the way a real change would appear: the current fact says one
        // thing and the next look says another.
        $disk = ServerFact::query()->where('managed_server_id', $server->getKey())->where('key', 'disk.1')->firstOrFail();
        $disk->forceFill(['value' => 'sata 960 GiB'])->save();

        $this->actingAs($operator)->postJson("/api/admin/infrastructure/servers/{$server->id}/discover")->assertOk();

        $history = ServerFact::query()
            ->where('managed_server_id', $server->getKey())
            ->where('key', 'disk.1')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->superseded_at, 'The old value is kept, marked as no longer current.');
        $this->assertSame('sata 960 GiB', $history[0]->value);
        $this->assertNull($history[1]->superseded_at);
        $this->assertSame('nvme 3840 GiB', $history[1]->value);

        // Exactly one current row per key, which is also what the database
        // enforces on its own.
        $this->assertSame(1, ServerFact::query()->where('managed_server_id', $server->getKey())->where('key', 'disk.1')->current()->count());
    }

    #[Test]
    public function a_fact_the_machine_stops_reporting_stops_being_current(): void
    {
        $server = $this->reachable();
        $operator = $this->operator();

        // A fact from an earlier look that this look will not confirm.
        ServerFact::create([
            'managed_server_id' => $server->getKey(),
            'key' => 'disk.7',
            'value' => 'gone',
            'source' => FactSource::Discovered,
            'observed_at' => now()->subDay(),
        ]);

        // And one an operator declared, which discovery has no authority over.
        ServerFact::create([
            'managed_server_id' => $server->getKey(),
            'key' => 'rack.label',
            'value' => 'A-17',
            'source' => FactSource::Declared,
            'observed_at' => now()->subDay(),
        ]);

        $this->actingAs($operator)->postJson("/api/admin/infrastructure/servers/{$server->id}/discover")->assertOk();

        $this->assertNotNull(ServerFact::query()->where('key', 'disk.7')->firstOrFail()->superseded_at);
        $this->assertNull(ServerFact::query()->where('key', 'rack.label')->firstOrFail()->superseded_at);
    }

    #[Test]
    public function a_machine_that_did_not_answer_usefully_yields_no_facts_and_none_are_invented(): void
    {
        $server = ManagedServer::factory()->withBmc()->classified(SafetyClass::DiscoveryOnly)->reachableAs('auth-failed')->create([
            'credential_reference_id' => CredentialReference::factory()->create(['backend_reference' => self::SECRET])->getKey(),
        ]);

        $response = $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/discover");

        $response->assertOk();
        $response->assertJsonPath('data.usable', false);
        $response->assertJsonPath('data.facts', 0);

        $this->assertDatabaseCount('server_facts', 0);
        $this->assertNull($server->fresh()->last_discovery_at);
        $this->assertDatabaseMissing('audit_log', ['action' => AuditAction::ServerDiscovered->value]);
        // The test itself is on record, so the screen can say why.
        $this->assertSame(ConnectionState::AuthFailed, $server->fresh()->connection_state);
    }

    #[Test]
    public function a_machine_nobody_may_touch_is_not_looked_at_either(): void
    {
        $server = $this->reachable(SafetyClass::DoNotTouch);

        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/discover")
            ->assertConflict()
            ->assertJsonPath('error.code', 'safety_refused');

        $this->assertDatabaseCount('server_facts', 0);
        $this->assertDatabaseCount('connection_tests', 0);
    }

    #[Test]
    public function discovery_never_changes_the_machine_or_what_may_be_done_to_it(): void
    {
        /*
         * The property DISCOVERY_ONLY promises. Everything about the machine
         * that a person decided is identical before and after a look: the
         * classification, the clearance, the credential, the addresses.
         */
        $server = $this->reachable();
        $before = $server->fresh()->only(['safety_class', 'allow_reimage', 'credential_reference_id', 'management_address', 'bmc_address', 'environment', 'state']);

        $this->actingAs($this->operator())->postJson("/api/admin/infrastructure/servers/{$server->id}/discover")->assertOk();

        $this->assertEquals($before, $server->fresh()->only(array_keys($before)));
    }

    #[Test]
    public function a_bmc_in_another_environment_cannot_be_bound_to_the_machine_in_the_first_place(): void
    {
        $server = ManagedServer::factory()->create(['environment' => DeploymentEnvironment::Production]);

        $this->actingAs($this->operator())->postJson('/api/admin/providers', [
            'name' => 'bmc-crossed',
            'driver' => 'fake_bmc',
            'category' => ProviderCategory::Bmc->value,
            'environment' => DeploymentEnvironment::Staging->value,
            'endpoint' => 'fake://connected',
            'managed_server_id' => $server->id,
        ])->assertConflict();
    }
}
