<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Actions\CloseAJobWhoseServiceEnded;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A job that changes a resource, stopped in review on a service that has
 * since ended, can be closed (X9-1, the re-audit after round eight).
 *
 * A resize, a package change or a stop in `needs_review` on a terminated
 * service was refused by a retry (the service is over) and by an adoption
 * (it builds nothing), so it stayed in review for ever and the
 * ProvisioningJobsAwaitingReview alert with it. Closing is its way out: behind
 * provisioning.retry, audited with the evidence, and the job `cancelled`.
 */
final class AJobWhoseServiceEndedCanBeClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind, string}>
     */
    public static function theThreeKindsTheReAuditFound(): iterable
    {
        yield 'resize' => [ProvisioningJobKind::Resize, 'vps'];
        yield 'change_hosting_package' => [ProvisioningJobKind::ChangeHostingPackage, 'shared_hosting'];
        yield 'stop' => [ProvisioningJobKind::Stop, 'vps'];
    }

    #[Test]
    #[DataProvider('theThreeKindsTheReAuditFound')]
    public function a_job_a_retry_and_an_adoption_both_refuse_is_closed(ProvisioningJobKind $kind, string $serviceKind): void
    {
        $job = $this->jobInReview($kind, $serviceKind, ServiceStatus::Terminated);
        $operator = $this->operator(Role::SuperAdmin);

        // The two ways out there were, and why neither is one.
        $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', ['evidence' => 'Service has ended.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.retry_after_the_service_ended');
        $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/adopt', ['provider_reference' => '101', 'evidence' => 'Service has ended.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.adoption_not_a_build');
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);

        $listed = collect($this->actingAs($operator)->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))->firstWhere('id', $job->id);
        $this->assertTrue($listed['closable'] ?? null, 'The review list does not say the job can be closed.');

        $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Service terminated on 1 Sept; the machine is gone.'])
            ->assertOk()
            ->assertJsonPath('data.status', ProvisioningJobStatus::Cancelled->value);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Cancelled, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame('The resize could not be confirmed.', $job->last_error, 'The reason the job stopped was cleared.');
        $this->assertSame('terminated', $job->result[CloseAJobWhoseServiceEnded::CLOSED]['service_status'] ?? null);
        $this->assertSame(0, ProvisioningJob::query()->where('status', ProvisioningJobStatus::NeedsReview)->count(), 'The job is still counted as awaiting review.');
        Queue::assertNothingPushed();

        $entry = AuditEntry::query()->where('action', AuditAction::ProvisioningClosed)->sole();
        $this->assertSame((string) $job->id, (string) $entry->subject_id);
        $this->assertStringContainsString('the machine is gone', (string) ($entry->context['evidence'] ?? ''));
        $this->assertSame($kind->value, $entry->context['kind'] ?? null);

        // Once closed, it is closed.
        $this->actingAs($operator)->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Again.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.close_not_in_review');
    }

    #[Test]
    public function a_job_whose_service_has_not_ended_is_not_closed(): void
    {
        $job = $this->jobInReview(ProvisioningJobKind::Resize, 'vps', ServiceStatus::Active);

        $this->assertRefused($job, 'provisioning.close_service_not_ended');

        $orphan = $this->jobInReview(ProvisioningJobKind::Stop, 'vps', ServiceStatus::Terminated);
        $orphan->forceFill(['service_id' => null])->save();
        $this->assertRefused($orphan, 'provisioning.close_service_not_ended');
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function kindsAClosedDoesNotTake(): iterable
    {
        foreach (ProvisioningJobKind::cases() as $kind) {
            if (! in_array($kind, CloseAJobWhoseServiceEnded::CLOSABLE_KINDS, true)) {
                yield $kind->value => [$kind];
            }
        }
    }

    #[Test]
    #[DataProvider('kindsAClosedDoesNotTake')]
    public function a_build_a_destroy_or_a_rebuild_is_not_closed_even_on_an_ended_service(ProvisioningJobKind $kind): void
    {
        $job = $this->jobInReview($kind, 'vps', ServiceStatus::Terminated);

        $this->assertRefused($job, 'provisioning.close_not_for_this_kind');
    }

    #[Test]
    public function a_job_not_in_review_is_not_closed(): void
    {
        $job = $this->jobInReview(ProvisioningJobKind::Resize, 'vps', ServiceStatus::Terminated);
        $job->forceFill(['status' => ProvisioningJobStatus::Failed])->save();

        $this->assertRefused($job, 'provisioning.close_not_in_review');
    }

    #[Test]
    public function closing_is_behind_provisioning_retry_and_needs_evidence(): void
    {
        $job = $this->jobInReview(ProvisioningJobKind::Resize, 'vps', ServiceStatus::Terminated);

        // Support can see the queue and cannot retry, adopt or close.
        $this->actingAs($this->operator(Role::Support))
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Service has ended.'])
            ->assertForbidden();

        $this->actingAs($this->operator(Role::Noc))
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', [])
            ->assertStatus(422);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertSame(0, AuditEntry::query()->count());

        $this->actingAs($this->operator(Role::Noc))
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Service has ended.'])
            ->assertOk();
    }

    private function assertRefused(ProvisioningJob $job, string $code): void
    {
        $status = $job->status;
        $operator = $this->operator(Role::SuperAdmin);

        $listed = collect($this->actingAs($operator)->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))->firstWhere('id', $job->id);
        $this->assertFalse($listed['closable'] ?? null, 'The review list offers a close the route refuses.');

        $this->actingAs($operator)
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Service has ended.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', $code);

        $this->assertSame($status, $job->refresh()->status);
        $this->assertArrayNotHasKey(CloseAJobWhoseServiceEnded::CLOSED, $job->result ?? []);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::ProvisioningClosed)->count());
    }

    private function jobInReview(ProvisioningJobKind $kind, string $serviceKind, ServiceStatus $serviceStatus): ProvisioningJob
    {
        $service = Service::factory()->create(['status' => $serviceStatus, 'kind' => $serviceKind]);

        return ProvisioningJob::factory()->create([
            'kind' => $kind,
            'service_id' => $service->getKey(),
            'customer_id' => $service->customer_id,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'last_error' => 'The resize could not be confirmed.',
            'attempts' => 5,
            'max_attempts' => 5,
        ]);
    }

    private function operator(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
