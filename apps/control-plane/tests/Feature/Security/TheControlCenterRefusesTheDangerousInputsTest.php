<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
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
 * An operator with every control-centre permission, trying the things the
 * brief names: pointing a provider at the metadata service, handing a
 * playbook a shell, using a staging token against production, replaying an
 * approval, and running something destructive on a machine nobody cleared.
 * Each one is refused over HTTP, before anything is dialled or written.
 */
final class TheControlCenterRefusesTheDangerousInputsTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'LYNOMIA_TEST_ATTACK_SECRET';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
        putenv(self::SECRET.'=not-a-real-secret');

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET);

        parent::tearDown();
    }

    #[Test]
    public function a_provider_cannot_be_pointed_at_this_host_the_metadata_service_or_a_private_address_it_has_no_business_with(): void
    {
        foreach ([
            'https://127.0.0.1/',
            'https://localhost/',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/',
            'http://api.example.test/',
            'https://root:secret@api.example.test/',
            'https://10.66.0.5/',
            'file:///etc/passwd',
        ] as $endpoint) {
            $response = $this->actingAs($this->operator)->postJson('/api/admin/providers', [
                'name' => 'dns-'.uniqid(),
                'driver' => 'cloudflare',
                'category' => ProviderCategory::Dns->value,
                'environment' => DeploymentEnvironment::Production->value,
                'endpoint' => $endpoint,
            ]);

            $response->assertConflict();
            $response->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertDatabaseCount('provider_instances', 0);
    }

    #[Test]
    public function a_row_that_arrived_by_another_road_is_still_refused_before_a_socket_opens(): void
    {
        // Written straight to the table, as a seeder or a migration might.
        $provider = ProviderInstance::factory()->of(ProviderCategory::Dns)->create([
            'driver' => 'fake',
            'endpoint' => 'https://169.254.169.254/latest/meta-data/',
            'connection_state' => ConnectionState::NotTested,
        ]);

        $response = $this->actingAs($this->operator)->postJson("/api/admin/providers/{$provider->getKey()}/connection-test");

        $response->assertConflict();
        $response->assertJsonPath('error.code', 'endpoint_refused');
        $this->assertSame(ConnectionState::NotTested, $provider->fresh()->connection_state, 'Nothing was dialled, so nothing was recorded.');
    }

    #[Test]
    public function a_machine_cannot_be_registered_with_this_host_as_its_address(): void
    {
        foreach (['127.0.0.1', 'localhost', '169.254.169.254', 'https://10.66.0.2/', 'root@10.66.0.2'] as $address) {
            $this->actingAs($this->operator)->postJson('/api/admin/infrastructure/servers', [
                'name' => 'node-'.uniqid(),
                'environment' => DeploymentEnvironment::Staging->value,
                'management_address' => $address,
            ])->assertConflict()->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertDatabaseCount('managed_servers', 0);

        // The management network is private, and that is fine.
        $this->actingAs($this->operator)->postJson('/api/admin/infrastructure/servers', [
            'name' => 'node-ok',
            'environment' => DeploymentEnvironment::Staging->value,
            'management_address' => '10.66.0.2',
        ])->assertCreated();
    }

    #[Test]
    public function a_staging_credential_is_never_tried_against_production(): void
    {
        $staging = CredentialReference::factory()->create([
            'state' => CredentialState::Valid,
            'environment' => DeploymentEnvironment::Staging,
            'backend_reference' => self::SECRET,
        ]);

        $production = ProviderInstance::factory()->of(ProviderCategory::Registrar)->create([
            'driver' => 'fake',
            'environment' => DeploymentEnvironment::Production,
            'endpoint' => 'fake://connected',
        ]);

        // At attachment.
        $this->actingAs($this->operator)->postJson("/api/admin/providers/{$production->getKey()}/credential", ['credential_id' => $staging->getKey()])
            ->assertConflict();

        // And at use, for a row that skipped attachment.
        $production->forceFill(['credential_reference_id' => $staging->getKey()])->save();
        $tested = $this->actingAs($this->operator)->postJson("/api/admin/providers/{$production->getKey()}/connection-test");

        // The test runs — the fake driver exists in this environment — but
        // with no secret, because the staging reference was never resolved
        // for a production target. The fake answers as a real endpoint
        // would to an empty credential.
        $tested->assertOk();
        $this->assertNotSame(ConnectionState::Connected, $production->fresh()->connection_state);
        $this->assertFalse($production->fresh()->connection_state->usable());
        $this->assertStringNotContainsString('not-a-real-secret', $tested->getContent());
        $this->assertStringNotContainsString('not-a-real-secret', (string) json_encode(DB::table('audit_log')->pluck('context')->all()));
    }

    #[Test]
    public function a_destructive_plan_needs_the_name_typed_and_still_will_not_run_on_a_machine_nobody_cleared(): void
    {
        // A destructive plan does not exist in the catalogue today, so one is
        // written directly: the approval and run guards must hold regardless
        // of how the row came to be.
        $server = ManagedServer::factory()->classified(SafetyClass::ReimageAllowed)->reachableAs('connected')->create();
        $this->actingAs($this->operator)->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['profile' => 'redis'])->assertOk();

        $plan = DeploymentPlan::query()->create([
            'managed_server_id' => $server->getKey(),
            'changes' => [['component' => 'disk', 'action' => 'repartition', 'role' => 'none', 'risk' => 'destructive', 'requires_reboot' => true, 'configuration' => [], 'reason' => 'test']],
            'unchanged' => [],
            'blockers' => [],
            'risk' => PlanRisk::Destructive,
            'required_safety_class' => SafetyClass::ReimageAllowed,
            'requires_reboot' => true,
            'is_destructive' => true,
            'is_applicable' => true,
            'fingerprint' => str_repeat('d', 64),
        ]);

        $approver = User::factory()->create();
        $approver->syncRoles([Role::SuperAdmin->value]);

        // Without the name, and with the wrong name.
        $this->actingAs($approver)->postJson("/api/admin/infrastructure/plans/{$plan->getKey()}/approve", ['reason' => 'Go.'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'confirmation_required');
        $this->actingAs($approver)->postJson("/api/admin/infrastructure/plans/{$plan->getKey()}/approve", ['reason' => 'Go.', 'confirm_name' => 'some-other-box'])
            ->assertUnprocessable();

        $this->actingAs($approver)->postJson("/api/admin/infrastructure/plans/{$plan->getKey()}/approve", ['reason' => 'Reviewed.', 'confirm_name' => $server->name])
            ->assertOk();

        // Approved, and still refused: reimage-allowed is not cleared.
        $run = $this->actingAs($this->operator)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/deployments", ['kind' => 'apply']);
        $run->assertConflict();
        $run->assertJsonPath('error.code', 'safety_refused');
        $this->assertDatabaseCount('deployment_jobs', 0);
    }

    #[Test]
    public function an_approval_cannot_be_moved_to_another_plan_by_editing_the_request(): void
    {
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->reachableAs('connected')->create();
        $this->actingAs($this->operator)->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['profile' => 'redis'])->assertOk();
        $planned = $this->actingAs($this->operator)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/plan")->assertOk();

        $approver = User::factory()->create();
        $approver->syncRoles([Role::SuperAdmin->value]);
        $this->actingAs($approver)->postJson("/api/admin/infrastructure/plans/{$planned->json('data.id')}/approve", ['reason' => 'Reviewed.'])->assertOk();

        // The approval row is for fingerprint A. Somebody changes the plan's
        // fingerprint underneath it — a direct edit, since no endpoint does.
        DeploymentPlan::query()->whereKey($planned->json('data.id'))->update(['fingerprint' => str_repeat('e', 64)]);

        $run = $this->actingAs($this->operator)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/deployments", ['kind' => 'apply']);
        $run->assertConflict();
        $run->assertJsonPath('error.details.reason', 'not_approved');
        $this->assertDatabaseCount('deployment_jobs', 0);
    }
}
