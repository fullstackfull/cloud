<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an operator can do about work that stopped, and what they cannot.
 *
 * Two capabilities the platform described and did not have. The job state
 * machine carried `failed → queued` with a comment saying an operator does
 * this, and nothing could; `provisioning.retried` sat in the audit vocabulary
 * with no producer. Both reinstall lifecycles recorded a rebuild's every phase
 * and neither had a screen, so "was my server wiped?" was a question answered
 * with SQL.
 *
 * The refusals matter more than the capabilities. A retry that could run a
 * rebuild again, or a verdict that could be applied to an operation still in
 * flight, would be a worse platform than one with no buttons at all.
 */
final class TheOperationsQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_operator_can_run_a_failed_build_again(): void
    {
        Queue::fake();

        $service = Service::factory()->create(['status' => ServiceStatus::Failed, 'kind' => 'vps']);

        $job = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::CreateVps,
            'service_id' => $service->getKey(),
            'customer_id' => $service->customer_id,
            'status' => ProvisioningJobStatus::Failed,
            'failure_class' => FailureClass::Capacity,
            'last_error' => 'no node had room for 8 vCPU',
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'pve-kw-04 was added to the cluster this morning and has 96 free cores.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProvisioningJobStatus::Queued->value);

        Queue::assertPushed(RunProvisioningJob::class);

        // The service goes back to provisioning with it. Without that the
        // retry would succeed at the hypervisor and be unable to say so.
        $this->assertSame(ServiceStatus::Provisioning, $service->refresh()->status);

        // One attempt, not a new budget.
        $this->assertSame(3, $job->refresh()->max_attempts);

        // And the failure that prompted the retry is still readable.
        $this->assertSame('no node had room for 8 vCPU', $job->last_error);

        $entry = AuditEntry::query()->where('action', AuditAction::ProvisioningRetried)->sole();

        $this->assertStringContainsString('pve-kw-04', (string) ($entry->context['evidence'] ?? ''));
    }

    #[Test]
    public function a_job_that_already_built_something_is_adopted_rather_than_retried(): void
    {
        Queue::fake();

        $job = ProvisioningJob::factory()->create([
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'result' => ['provider_reference' => '4412'],
        ]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'The customer says nothing arrived.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.retry_would_duplicate');

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);

        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function a_rebuild_that_has_already_replaced_the_disk_is_never_run_again(): void
    {
        Queue::fake();

        $job = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
        ]);

        VmReinstall::query()->create([
            'virtual_machine_id' => VirtualMachine::factory()->create()->getKey(),
            'provisioning_job_id' => $job->getKey(),
            'state' => ReinstallState::Indeterminate,
            'state_changed_at' => now(),
            'destroyed_at' => now(),
        ]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'The customer asked us to try again.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.retry_destroys_again');

        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function a_retry_requires_the_evidence_the_operator_looked_at(): void
    {
        Queue::fake();

        $job = ProvisioningJob::factory()->create(['status' => ProvisioningJobStatus::Failed]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [])
            ->assertStatus(422);

        $this->assertSame(ProvisioningJobStatus::Failed, $job->refresh()->status);
        $this->assertSame(0, AuditEntry::query()->count());

        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function a_job_that_has_not_stopped_is_not_retried(): void
    {
        Queue::fake();

        // A running job is being worked on by somebody. Queueing it again is
        // how one order becomes two machines.
        $job = ProvisioningJob::factory()->create(['status' => ProvisioningJobStatus::Running]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'It has been running for a while.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.retry_not_settled');

        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function the_queue_shows_both_kinds_of_rebuild_and_says_which_are_waiting(): void
    {
        $this->aVpsRebuild(ReinstallState::Indeterminate, destroyed: true);
        $this->aVpsRebuild(ReinstallState::Completed, destroyed: true);
        $this->aDedicatedRebuild(DedicatedReinstallState::Installing);

        $all = $this->actingAs($this->operator())
            ->getJson('/api/admin/operations/reinstalls')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $types = array_column($all->json('data'), 'type');

        $this->assertContains('vps_reinstall', $types);
        $this->assertContains('dedicated_reinstall', $types);

        // The queue an NOC shift actually works: the ones nobody can settle.
        $waiting = $this->actingAs($this->operator())
            ->getJson('/api/admin/operations/reinstalls?needs_attention=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame(ReinstallState::Indeterminate->value, $waiting->json('data.0.state'));
        $this->assertTrue($waiting->json('data.0.data_destroyed'));
    }

    #[Test]
    public function an_operator_confirms_a_rebuild_the_platform_could_not_verify(): void
    {
        $operation = $this->aVpsRebuild(ReinstallState::Indeterminate, destroyed: true);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/operations/reinstalls/vps_reinstall/'.$operation->id.'/resolve', [
                'verdict' => 'completed',
                'evidence' => 'VM 910 on pve-kw-03 is running Rocky 10 and answering on port 22.',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', ReinstallState::Completed->value);

        // One decision, one outcome: the job the operation belonged to is
        // settled too, rather than left in review beside a resolved rebuild.
        $this->assertSame(
            ProvisioningJobStatus::Succeeded,
            ProvisioningJob::query()->findOrFail($operation->provisioning_job_id)->status,
        );

        $entry = AuditEntry::query()->where('action', AuditAction::ReinstallConfirmed)->sole();

        $this->assertStringContainsString('port 22', (string) ($entry->context['evidence'] ?? ''));
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());

        // And the customer, who has been waiting since it stopped, is told.
        $this->assertSame(
            NotificationType::ReinstallCompleted,
            Notification::query()->where('customer_id', $operation->customer_id)->sole()->type,
        );
    }

    #[Test]
    public function a_verdict_requires_saying_what_was_seen(): void
    {
        $operation = $this->aVpsRebuild(ReinstallState::NeedsReview, destroyed: true);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/operations/reinstalls/vps_reinstall/'.$operation->id.'/resolve', [
                'verdict' => 'completed',
            ])
            ->assertStatus(422);

        $this->assertSame(ReinstallState::NeedsReview, $operation->refresh()->state);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function an_operation_still_in_flight_cannot_be_declared_finished(): void
    {
        /*
         * The one that would make this screen dangerous. A rebuild that is
         * still installing is not waiting for a person's opinion, and marking
         * it complete would tell a customer their server is ready while the
         * installer is still writing to the disk.
         */
        $operation = $this->aDedicatedRebuild(DedicatedReinstallState::Installing);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/operations/reinstalls/dedicated_reinstall/'.$operation->id.'/resolve', [
                'verdict' => 'completed',
                'evidence' => 'It looks fine to me.',
            ])
            ->assertStatus(422);

        $this->assertSame(DedicatedReinstallState::Installing, $operation->refresh()->state);
    }

    #[Test]
    public function one_operation_shows_everything_that_happened_to_it(): void
    {
        $operation = $this->aVpsRebuild(ReinstallState::NeedsReview, destroyed: true);

        $this->actingAs($this->operator())
            ->getJson('/api/admin/operations/reinstalls/vps_reinstall/'.$operation->id)
            ->assertOk()
            ->assertJsonPath('data.operation.state', ReinstallState::NeedsReview->value)
            ->assertJsonPath('data.operation.data_destroyed', true)
            ->assertJsonPath('data.job.id', $operation->provisioning_job_id);
    }

    private function aVpsRebuild(ReinstallState $state, bool $destroyed): VmReinstall
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $job = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'customer_id' => $customer->getKey(),
            'status' => $state->needsAttention()
                ? ProvisioningJobStatus::NeedsReview
                : ProvisioningJobStatus::Succeeded,
        ]);

        /** @var VmReinstall $operation */
        $operation = VmReinstall::query()->create([
            'virtual_machine_id' => VirtualMachine::factory()->create()->getKey(),
            'customer_id' => $customer->getKey(),
            'provisioning_job_id' => $job->getKey(),
            'state' => $state,
            'state_changed_at' => now(),
            'destroyed_at' => $destroyed ? now() : null,
            'provider_resource_id' => '910',
            'provider_node' => 'pve-kw-03',
        ]);

        return $operation;
    }

    private function aDedicatedRebuild(DedicatedReinstallState $state): DedicatedReinstall
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $server = DedicatedServer::factory()->create();

        $job = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallDedicated,
            'customer_id' => $customer->getKey(),
            'status' => ProvisioningJobStatus::Running,
        ]);

        /** @var DedicatedReinstall $operation */
        $operation = DedicatedReinstall::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'customer_id' => $customer->getKey(),
            'provisioning_job_id' => $job->getKey(),
            'state' => $state,
            'state_changed_at' => now(),
            'destructive_started_at' => now(),
        ]);

        return $operation;
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
