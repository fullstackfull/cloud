<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One super admin is enough to put a machine into service (F-03 note,
 * re-audit of round four).
 *
 * F-03 as filed ended "Deploying a machine then requires two distinct Super
 * Admins". The closure rests on the chain splitting across roles — the
 * infrastructure admin plans and runs, only the approver needs
 * `deployment.approve` — but the chain's own test walks it with two super
 * admins, so nothing held the claim that one is enough. This walks it on a
 * deployment that has exactly one: established by `operator:bootstrap`, who
 * invites an infrastructure admin through the operator surface; the
 * infrastructure admin assigns and plans, the one super admin approves, the
 * infrastructure admin deploys, and the run completes against the fake
 * controller.
 */
final class OneSuperAdminIsEnoughToDeployAMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function the_chain_completes_with_exactly_one_super_admin(): void
    {
        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test', '--name' => 'First Operator'])
            ->assertSuccessful();

        /** @var User $superAdmin */
        $superAdmin = User::query()->where('email', 'ops@lynomia.test')->sole();

        $this->as($superAdmin)
            ->postJson('/api/admin/operators', [
                'email' => 'infra@lynomia.test',
                'name' => 'Infrastructure Admin',
                'roles' => [Role::InfrastructureAdmin->value],
            ])
            ->assertSuccessful();

        /** @var User $infra */
        $infra = User::query()->where('email', 'infra@lynomia.test')->sole();

        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count(), 'Precondition: exactly one super admin.');
        $this->assertFalse($infra->hasRole(Role::SuperAdmin->value));

        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->reachableAs('connected')->create();

        $this->as($infra)
            ->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['profile' => 'redis', 'overrides' => []])
            ->assertOk();

        $planId = (string) $this->as($infra)
            ->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/plan")
            ->assertOk()
            ->json('data.id');

        // The infrastructure admin cannot approve; the one super admin can.
        $this->as($infra)
            ->postJson("/api/admin/infrastructure/plans/{$planId}/approve", ['reason' => 'Reviewed the diff.'])
            ->assertForbidden();

        $this->as($superAdmin)
            ->postJson("/api/admin/infrastructure/plans/{$planId}/approve", ['reason' => 'Reviewed the diff.'])
            ->assertOk();

        $started = $this->as($infra)
            ->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/deployments", ['kind' => 'apply'])
            ->assertStatus(202);

        // The queue is synchronous under test, so the run has finished.
        $job = DeploymentJob::query()->findOrFail($started->json('data.id'));
        $this->assertSame(DeploymentState::Completed, $job->state);
        $this->assertSame($planId, $job->deployment_plan_id);
        $this->assertSame(ServerState::Managed, $server->fresh()?->state);

        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());
    }

    /**
     * Each person in a session of their own, as two people signing in from
     * two browsers are. The test client sends the portal's Origin, so every
     * request is a Sanctum SPA session request, and the session outlives the
     * request under test; Sanctum's AuthenticateSession then compares the
     * previous person's password hash with this one's and signs them out.
     * Factory users share one hash, which is why tests built only from them
     * never notice; the bootstrapped and the invited operator do not.
     */
    private function as(User $who): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($who);
    }
}
