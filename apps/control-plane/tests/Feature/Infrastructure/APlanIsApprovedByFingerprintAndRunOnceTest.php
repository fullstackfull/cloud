<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Jobs\RunDeploymentJob;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The chain end to end, over HTTP, against the fake controller — and every
 * place it must refuse. The property throughout: what runs is exactly what
 * was approved, once, on a machine classified to permit it, and a run that
 * stopped without an answer stops everything until a person answers.
 */
final class APlanIsApprovedByFingerprintAndRunOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function machine(SafetyClass $class = SafetyClass::ConfigurationAllowed, string $marker = 'connected'): ManagedServer
    {
        return ManagedServer::factory()->classified($class)->reachableAs($marker)->create();
    }

    private function assign(User $who, ManagedServer $server, string $profile = 'redis', array $overrides = []): TestResponse
    {
        return $this->actingAs($who)->putJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", [
            'profile' => $profile,
            'overrides' => $overrides,
        ]);
    }

    private function plan(User $who, ManagedServer $server): TestResponse
    {
        return $this->actingAs($who)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/plan");
    }

    private function approve(User $who, string $planId, string $reason = 'Reviewed the diff.'): TestResponse
    {
        return $this->actingAs($who)->postJson("/api/admin/infrastructure/plans/{$planId}/approve", ['reason' => $reason]);
    }

    private function deploy(User $who, ManagedServer $server, string $kind = 'apply'): TestResponse
    {
        return $this->actingAs($who)->postJson("/api/admin/infrastructure/servers/{$server->getKey()}/deployments", ['kind' => $kind]);
    }

    /**
     * Assigned, planned, approved by somebody else. The state most tests
     * start from.
     */
    private function approvedMachine(SafetyClass $class = SafetyClass::ConfigurationAllowed, string $marker = 'connected'): array
    {
        $planner = $this->operator();
        $approver = $this->operator();
        $server = $this->machine($class, $marker);

        $this->assign($planner, $server)->assertOk();
        $planned = $this->plan($planner, $server)->assertOk();
        $this->approve($approver, $planned->json('data.id'))->assertOk();

        return [$server, $planner, $approver, $planned->json('data.id')];
    }

    #[Test]
    public function the_catalogue_is_readable_and_names_roles_not_commands(): void
    {
        $response = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/profiles');

        $response->assertOk();
        $redis = collect($response->json('data'))->firstWhere('key', 'redis');
        $this->assertSame('redis.yml', $redis['playbook']);
        $this->assertSame(['common', 'hardening', 'redis'], array_column($redis['components'], 'key'));
        $this->assertSame(['maxmemory'], collect($redis['components'])->firstWhere('key', 'redis')['accepts']);
    }

    #[Test]
    public function desired_state_refuses_overrides_no_component_declares_and_values_that_smell_of_a_shell(): void
    {
        $server = $this->machine();
        $operator = $this->operator();

        $this->assign($operator, $server, 'redis', ['redis.bind_address' => '0.0.0.0'])
            ->assertConflict()
            ->assertJsonPath('error.details.reason', 'override_refused');

        foreach (['1gb; rm -rf /', '{{ lookup("file", "/etc/shadow") }}', '$(id)', "1gb\nsecond line", '`whoami`'] as $value) {
            $this->assign($operator, $server, 'redis', ['redis.maxmemory' => $value])
                ->assertConflict()
                ->assertJsonPath('error.details.reason', 'override_refused');
        }

        $this->assign($operator, $server, 'no-such-profile')->assertConflict()->assertJsonPath('error.details.reason', 'profile_unknown');

        $this->assertDatabaseCount('desired_states', 0);

        $ok = $this->assign($operator, $server, 'redis', ['redis.maxmemory' => '1gb', 'hardening.ssh_port' => 2222]);
        $ok->assertOk();
        $ok->assertJsonPath('data.profile', 'redis');
        // Read as a map, not a path: the keys carry dots.
        $this->assertSame(['hardening.ssh_port' => '2222', 'redis.maxmemory' => '1gb'], $ok->json('data.overrides'));
        $this->assertSame(ServerState::Profiled, $server->fresh()->state);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DesiredStateAssigned->value]);
    }

    #[Test]
    public function a_plan_prices_its_changes_and_the_same_work_keeps_the_same_fingerprint(): void
    {
        $server = $this->machine();
        $operator = $this->operator();

        $this->plan($operator, $server)->assertConflict()->assertJsonPath('error.details.reason', 'no_desired_state');

        $this->assign($operator, $server, 'redis', ['redis.maxmemory' => '1gb']);
        $first = $this->plan($operator, $server)->assertOk();

        $first->assertJsonPath('data.is_applicable', true);
        $first->assertJsonPath('data.risk', 'moderate');
        $first->assertJsonPath('data.required_safety_class', 'configuration_allowed');
        $first->assertJsonPath('data.approval', null);
        $this->assertSame(['common', 'hardening', 'redis'], array_column($first->json('data.changes'), 'component'));
        $this->assertSame('1gb', collect($first->json('data.changes'))->firstWhere('component', 'redis')['configuration']['maxmemory']);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::PlanComputed->value]);

        $again = $this->plan($operator, $server)->assertOk();
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame($first->json('data.fingerprint'), $again->json('data.fingerprint'));
        $this->assertDatabaseCount('deployment_plans', 1);

        // A different override is different work: a new plan, a new fingerprint.
        $this->assign($operator, $server, 'redis', ['redis.maxmemory' => '2gb']);
        $changed = $this->plan($operator, $server)->assertOk();
        $this->assertNotSame($first->json('data.fingerprint'), $changed->json('data.fingerprint'));
        $this->assertDatabaseCount('deployment_plans', 2);
    }

    #[Test]
    public function a_machine_classified_below_the_plans_risk_gets_a_blocker_and_cannot_be_approved(): void
    {
        $server = $this->machine(SafetyClass::DiscoveryOnly);
        $operator = $this->operator();

        $this->assign($operator, $server)->assertOk();
        $planned = $this->plan($operator, $server)->assertOk();

        $planned->assertJsonPath('data.is_applicable', false);
        $this->assertSame('safety_class', $planned->json('data.blockers.0.code'));

        $this->approve($this->operator(), $planned->json('data.id'))
            ->assertConflict()
            ->assertJsonPath('error.details.reason', 'plan_not_applicable');
    }

    #[Test]
    public function the_person_who_planned_it_does_not_approve_it(): void
    {
        $server = $this->machine();
        $planner = $this->operator();

        $this->assign($planner, $server)->assertOk();
        $planned = $this->plan($planner, $server)->assertOk();

        $this->approve($planner, $planned->json('data.id'))
            ->assertConflict()
            ->assertJsonPath('error.details.reason', 'four_eyes');

        $approved = $this->approve($this->operator(), $planned->json('data.id'))->assertOk();
        $this->assertSame($planned->json('data.fingerprint'), $approved->json('data.approval.approved_fingerprint'));
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::PlanApproved->value]);
    }

    #[Test]
    public function approving_is_its_own_permission_that_the_infrastructure_admin_does_not_hold(): void
    {
        $server = $this->machine();
        $admin = $this->operator(Role::InfrastructureAdmin);

        $this->assign($admin, $server)->assertOk();
        $planned = $this->plan($admin, $server)->assertOk();

        $this->approve($this->operator(Role::InfrastructureAdmin), $planned->json('data.id'))->assertForbidden();
        $this->actingAs($this->operator(Role::Support))->getJson('/api/admin/infrastructure/profiles')->assertForbidden();
        $this->assign($this->operator(Role::Noc), $server)->assertForbidden();
    }

    #[Test]
    public function a_re_plan_that_changes_the_work_revokes_the_approval_and_the_run_is_refused(): void
    {
        [$server, $planner] = $this->approvedMachine();

        $this->assign($planner, $server, 'redis', ['redis.maxmemory' => '4gb'])->assertOk();
        $replanned = $this->plan($planner, $server)->assertOk();

        $replanned->assertJsonPath('data.approval', null);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::PlanApprovalRevoked->value]);

        $this->deploy($this->operator(), $server)
            ->assertConflict()
            ->assertJsonPath('error.details.reason', 'not_approved');

        $this->assertDatabaseCount('deployment_jobs', 0);
    }

    #[Test]
    public function the_whole_chain_completes_and_the_machine_knows_what_it_has(): void
    {
        [$server, , , $planId] = $this->approvedMachine();

        $started = $this->deploy($this->operator(), $server);
        $started->assertStatus(202);

        // The queue is synchronous under test, so the run has finished.
        $job = DeploymentJob::query()->findOrFail($started->json('data.id'));
        $this->assertSame(DeploymentState::Completed, $job->state);
        $this->assertSame($planId, $job->deployment_plan_id);
        $this->assertContains('role:redis', array_column($job->steps, 'name'));
        $this->assertContains('verify:redis', array_column($job->steps, 'name'));

        // Verification wrote the facts, the machine moved to managed, and the
        // next plan has nothing to do.
        $present = ServerFact::query()->where('managed_server_id', $server->getKey())->current()->pluck('value', 'key')->all();
        $this->assertSame('true', $present['software.common.present']);
        $this->assertSame('true', $present['software.redis.present']);

        $fresh = $server->fresh();
        $this->assertSame(ServerState::Managed, $fresh->state);
        $this->assertNotNull($fresh->last_deployment_at);
        $this->assertNotNull($fresh->last_verification_at);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DeploymentFinished->value]);

        $nothing = $this->plan($this->operator(), $server)->assertOk();
        $nothing->assertJsonPath('is_applicable', null);
        $nothing->assertJsonPath('data.is_applicable', false);
        $this->assertSame([], $nothing->json('data.changes'));
        $this->assertCount(3, $nothing->json('data.unchanged'));
    }

    #[Test]
    public function the_worker_refuses_a_run_whose_approval_no_longer_covers_the_plan(): void
    {
        Queue::fake();
        [$server, $planner] = $this->approvedMachine();

        $queued = $this->deploy($this->operator(), $server)->assertStatus(202);
        $this->assertSame('queued', $queued->json('data.state'));

        // Between queueing and running, the work changed and was re-planned.
        $this->assign($planner, $server, 'redis', ['redis.maxmemory' => '8gb'])->assertOk();
        $this->plan($planner, $server)->assertOk();

        app()->call([new RunDeploymentJob($queued->json('data.id')), 'handle']);

        $job = DeploymentJob::query()->findOrFail($queued->json('data.id'));
        $this->assertSame(DeploymentState::Failed, $job->state);
        $this->assertSame('fingerprint_mismatch', $job->steps[0]['detail']);
        $this->assertStringContainsString('nothing was started', $job->failure_detail);
        $this->assertSame(ServerState::Profiled, $server->fresh()->state);
    }

    #[Test]
    public function a_run_that_outlives_its_deadline_is_indeterminate_and_blocks_the_machine_until_a_person_resolves_it(): void
    {
        [$server] = $this->approvedMachine(marker: 'apply-timeout');
        $operator = $this->operator();

        $started = $this->deploy($operator, $server)->assertStatus(202);
        $job = DeploymentJob::query()->findOrFail($started->json('data.id'));

        $this->assertSame(DeploymentState::Indeterminate, $job->state);
        $this->assertSame('timeout', $job->failure_class);
        $this->assertStringContainsString('part-way through', $job->failure_detail);

        // Never retried: one job, and a new request is refused at the door.
        $this->assertDatabaseCount('deployment_jobs', 1);
        $this->deploy($operator, $server)->assertConflict()->assertJsonPath('error.details.reason', 'unresolved');
        $this->deploy($operator, $server, 'verify')->assertConflict()->assertJsonPath('error.details.reason', 'unresolved');

        // The list puts it first, and says it waits for somebody.
        $list = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/deployments');
        $list->assertJsonPath('data.0.id', $job->getKey());
        $list->assertJsonPath('data.0.waits_for_somebody', true);

        // A person looked and says it is not done.
        $resolved = $this->actingAs($operator)->postJson("/api/admin/infrastructure/deployments/{$job->getKey()}/resolve", [
            'outcome' => 'failed',
            'reason' => 'Console shows the playbook died at the redis role; the service is not installed.',
        ]);
        $resolved->assertOk();
        $resolved->assertJsonPath('data.state', 'failed');
        $this->assertStringContainsString('Resolved by a person', $resolved->json('data.failure_detail'));
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DeploymentResolved->value]);

        // And now the machine may be tried again — with a fresh run.
        $this->deploy($operator, $server)->assertStatus(202);
        $this->assertDatabaseCount('deployment_jobs', 2);
    }

    #[Test]
    public function a_change_that_cannot_be_verified_needs_review_rather_than_claiming_success(): void
    {
        [$server] = $this->approvedMachine(marker: 'verify-fails');

        $started = $this->deploy($this->operator(), $server)->assertStatus(202);
        $job = DeploymentJob::query()->findOrFail($started->json('data.id'));

        $this->assertSame(DeploymentState::NeedsReview, $job->state);
        $this->assertStringContainsString('not present after the run', $job->failure_detail);
        $this->assertSame(ServerState::Profiled, $server->fresh()->state);
        $this->assertSame(0, ServerFact::query()->where('managed_server_id', $server->getKey())->count());
    }

    #[Test]
    public function a_playbook_that_fails_is_failed_with_its_class_and_its_steps(): void
    {
        [$server] = $this->approvedMachine(marker: 'apply-fails');

        $started = $this->deploy($this->operator(), $server)->assertStatus(202);

        $started->assertJsonPath('data.state', 'failed');
        $started->assertJsonPath('data.failure_class', 'permanent');
        $this->assertSame('failed', collect($started->json('data.steps'))->firstWhere('name', 'playbook')['outcome']);
    }

    #[Test]
    public function a_verify_looks_and_changes_nothing_and_a_do_not_touch_machine_is_not_even_looked_at(): void
    {
        $server = $this->machine(SafetyClass::DiscoveryOnly);
        $operator = $this->operator();
        $this->assign($operator, $server)->assertOk();

        $verified = $this->deploy($operator, $server, 'verify')->assertStatus(202);
        $verified->assertJsonPath('data.state', 'completed');
        $this->assertSame(ServerState::Profiled, $server->fresh()->state, 'A verify does not make a machine managed.');
        $this->assertNull($server->fresh()->last_deployment_at);
        $this->assertNotNull($server->fresh()->last_verification_at);

        $untouchable = $this->machine(SafetyClass::DoNotTouch);
        $this->deploy($operator, $untouchable, 'verify')->assertConflict()->assertJsonPath('error.code', 'safety_refused');
        $this->deploy($operator, $untouchable, 'apply')->assertConflict();
        $this->assertSame(1, DeploymentJob::query()->count());
    }

    #[Test]
    public function only_a_run_that_has_not_started_can_be_cancelled(): void
    {
        Queue::fake();
        [$server] = $this->approvedMachine();
        $operator = $this->operator();

        $queued = $this->deploy($operator, $server)->assertStatus(202);
        $cancelled = $this->actingAs($operator)->postJson("/api/admin/infrastructure/deployments/{$queued->json('data.id')}/cancel", ['reason' => 'Wrong window.']);
        $cancelled->assertOk()->assertJsonPath('data.state', 'cancelled');

        $this->actingAs($operator)->postJson("/api/admin/infrastructure/deployments/{$queued->json('data.id')}/cancel", ['reason' => 'Again.'])
            ->assertConflict()->assertJsonPath('error.details.reason', 'not_cancellable');
    }

    #[Test]
    public function a_run_whose_worker_vanished_is_marked_indeterminate_by_the_sweep_and_not_restarted(): void
    {
        [$server] = $this->approvedMachine();

        $job = DeploymentJob::query()->create([
            'managed_server_id' => $server->getKey(),
            'state' => DeploymentState::Applying,
            'kind' => 'apply',
            'idempotency_key' => 'stale-1',
            'started_at' => CarbonImmutable::now()->subHours(2),
        ]);

        $this->artisan('deployments:detect-stale')->assertSuccessful();

        $this->assertSame(DeploymentState::Indeterminate, $job->fresh()->state);
        $this->assertSame('timeout', $job->fresh()->failure_class);
        $this->assertDatabaseCount('deployment_jobs', 1);
    }

    #[Test]
    public function clearing_the_desired_state_revokes_the_approval(): void
    {
        [$server, , , $planId] = $this->approvedMachine();

        $this->actingAs($this->operator())->deleteJson("/api/admin/infrastructure/servers/{$server->getKey()}/desired-state", ['reason' => 'Machine repurposed.'])
            ->assertNoContent();

        $this->actingAs($this->operator(Role::Noc))->getJson("/api/admin/infrastructure/plans/{$planId}")
            ->assertOk()->assertJsonPath('data.approval', null);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DesiredStateCleared->value]);
    }
}
