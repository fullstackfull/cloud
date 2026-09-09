<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\DetectInfrastructureDrift;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Infrastructure drift is the same drift as everything else: one row per
 * subject in the queue a person already reads, counted on the metric a rule
 * already watches.
 */
final class AManagedMachineThatStopsMatchingItsProfileIsDriftTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
        $this->approver = User::factory()->create();
        $this->approver->syncRoles([Role::SuperAdmin->value]);
    }

    private function managed(): ManagedServer
    {
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->reachableAs('connected')->create();

        $this->actingAs($this->operator)->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['profile' => 'redis'])->assertOk();
        $plan = $this->actingAs($this->operator)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/plan")->assertOk();
        $this->actingAs($this->approver)->postJson("/api/admin/infrastructure/plans/{$plan->json('data.id')}/approve", ['reason' => 'Reviewed.'])->assertOk();
        $this->actingAs($this->operator)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/deployments", ['kind' => 'apply'])->assertStatus(202);

        $fresh = $server->fresh();
        $this->assertSame(ServerState::Managed, $fresh->state);

        return $fresh;
    }

    #[Test]
    public function a_machine_that_matches_its_profile_is_not_drift(): void
    {
        $this->managed();

        $outcome = app(DetectInfrastructureDrift::class)->execute();

        $this->assertSame(['examined' => 1, 'drifted' => 0], $outcome);
        $this->assertDatabaseCount('resource_drifts', 0);
    }

    #[Test]
    public function a_component_that_stops_being_present_is_recorded_once_and_counted_on_repeat(): void
    {
        $server = $this->managed();

        // The next look did not see redis. The fact is superseded, as a
        // discovery or a verify would supersede it.
        ServerFact::query()->where('managed_server_id', $server->getKey())->where('key', 'software.redis.present')->update(['superseded_at' => CarbonImmutable::now()]);

        $this->artisan('infrastructure:detect-drift')->assertSuccessful();

        $drift = ResourceDrift::query()->sole();
        $this->assertSame('infrastructure', $drift->provider);
        $this->assertSame('managed_server', $drift->resource_type);
        $this->assertSame($server->name, $drift->provider_reference);
        $this->assertSame(DriftKind::SpecMismatch, $drift->kind);
        $this->assertSame(DriftStatus::Open, $drift->status);
        $this->assertSame(['absent' => ['redis']], $drift->observed);
        $this->assertSame('redis', $drift->expected['profile']);

        // Seen again: the same row, counted, not a second row.
        app(DetectInfrastructureDrift::class)->execute();
        $this->assertDatabaseCount('resource_drifts', 1);
        $this->assertSame(2, $drift->fresh()->occurrences);

        // In the queue a person already reads, and on the metric a rule
        // already watches.
        $queue = $this->actingAs($this->operator)->getJson('/api/admin/drift');
        $queue->assertOk();
        $this->assertSame('infrastructure', collect($queue->json('data'))->firstWhere('resource_type', 'managed_server')['provider']);
    }

    #[Test]
    public function a_machine_that_was_never_brought_to_its_profile_is_work_not_drift(): void
    {
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->create();
        $this->actingAs($this->operator)->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['profile' => 'redis'])->assertOk();

        $this->assertSame(['examined' => 0, 'drifted' => 0], app(DetectInfrastructureDrift::class)->execute());
    }
}
