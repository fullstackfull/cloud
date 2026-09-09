<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TheOverviewAndTheSiteRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    #[Test]
    public function the_overview_counts_the_estate_and_says_what_needs_a_person(): void
    {
        $machine = ManagedServer::factory()->classified(SafetyClass::DiscoveryOnly)->create(['safety_changed_at' => CarbonImmutable::now()]);
        ManagedServer::factory()->create(); // never classified
        DeploymentJob::query()->create(['managed_server_id' => $machine->getKey(), 'state' => DeploymentState::Indeterminate, 'kind' => 'apply', 'idempotency_key' => 'x']);
        ProviderInstance::factory()->of(ProviderCategory::Dns)->enabled()->create(['readiness' => ReadinessState::NotReady]);
        CredentialReference::factory()->create(['state' => CredentialState::Missing]);
        Rack::factory()->create();

        $response = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/overview');

        $response->assertOk();
        $response->assertJsonPath('data.machines.total', 2);
        $response->assertJsonPath('data.machines.by_classification.do_not_touch', 1);
        $response->assertJsonPath('data.machines.by_classification.discovery_only', 1);
        $response->assertJsonPath('data.providers.by_state.enabled', 1);
        $response->assertJsonPath('data.sites.datacenters', 1);
        $response->assertJsonPath('data.sites.racks', 1);
        $response->assertJsonPath('data.attention.deployments_waiting', 1);
        $response->assertJsonPath('data.attention.providers_enabled_not_ready', 1);
        $response->assertJsonPath('data.attention.credentials_missing', 1);
        $response->assertJsonPath('data.attention.machines_never_classified', 1);
        $response->assertJsonPath('data.attention.infrastructure_drift_open', 0);
    }

    #[Test]
    public function a_datacenter_and_a_rack_are_registered_audited_and_listed_with_their_counts(): void
    {
        $region = Region::factory()->create();
        $operator = $this->operator();

        $dc = $this->actingAs($operator)->postJson('/api/admin/infrastructure/datacenters', [
            'region_id' => $region->getKey(),
            'slug' => 'kw-dc-2',
            'name' => 'Kuwait DC 2',
            'facility' => 'Zajil',
        ]);
        $dc->assertCreated()->assertJsonPath('data.slug', 'kw-dc-2')->assertJsonPath('data.region', $region->slug);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DatacenterRegistered->value]);

        // A slug is unique.
        $this->actingAs($operator)->postJson('/api/admin/infrastructure/datacenters', ['region_id' => $region->getKey(), 'slug' => 'kw-dc-2', 'name' => 'Again'])
            ->assertUnprocessable();

        $rack = $this->actingAs($operator)->postJson('/api/admin/infrastructure/racks', [
            'datacenter_id' => $dc->json('data.id'),
            'name' => 'R1',
            'row' => 'A',
            'units' => 42,
            'power_notes' => 'PDU-1 A/B',
            'network_notes' => 'sw-1 1-24',
        ]);
        $rack->assertCreated()->assertJsonPath('data.datacenter', 'kw-dc-2')->assertJsonPath('data.units', 42);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::RackRegistered->value]);

        // One name per datacenter.
        $this->actingAs($operator)->postJson('/api/admin/infrastructure/racks', ['datacenter_id' => $dc->json('data.id'), 'name' => 'R1', 'units' => 42])
            ->assertConflict()->assertJsonPath('error.details.reason', 'rack_exists');

        // A machine in the rack is counted on both.
        ManagedServer::factory()->create(['datacenter_id' => $dc->json('data.id'), 'rack_id' => $rack->json('data.id'), 'rack_unit' => 12]);

        $datacenters = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/datacenters')->assertOk();
        $row = collect($datacenters->json('data'))->firstWhere('slug', 'kw-dc-2');
        $this->assertSame(1, $row['racks']);
        $this->assertSame(1, $row['machines']);

        $racks = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/racks?datacenter='.$dc->json('data.id'))->assertOk();
        $this->assertSame(1, $racks->json('data.0.machines'));

        $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/regions')->assertOk()->assertJsonPath('data.0.slug', $region->slug);
    }

    #[Test]
    public function reading_is_the_view_permission_and_registering_is_manage(): void
    {
        $this->actingAs($this->operator(Role::Support))->getJson('/api/admin/infrastructure/overview')->assertForbidden();
        $this->actingAs($this->operator(Role::Noc))->postJson('/api/admin/infrastructure/racks', ['datacenter_id' => Datacenter::factory()->create()->getKey(), 'name' => 'R9', 'units' => 42])->assertForbidden();
    }
}
