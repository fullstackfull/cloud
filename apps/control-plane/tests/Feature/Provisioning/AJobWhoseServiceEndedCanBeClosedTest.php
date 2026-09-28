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
     * The kinds a close takes, written out: each changes a resource that
     * already exists and makes or removes none.
     *
     * @var list<ProvisioningJobKind>
     */
    private const array ACCEPTED = [
        ProvisioningJobKind::Start,
        ProvisioningJobKind::Stop,
        ProvisioningJobKind::Restart,
        ProvisioningJobKind::Resize,
        ProvisioningJobKind::ChangeHostingPackage,
    ];

    /**
     * The kinds a close refuses, written out rather than derived from
     * CLOSABLE_KINDS. Derived, the dataset shrank with every kind added to the
     * constant, so adding a build or a destroy to it - the job whose closing
     * would take the only pointer to a resource at the provider off the list,
     * the orphaned-resource class - left the whole suite green (band D of the
     * final audit). A build or a destroy may have left a resource; a rebuild
     * is settled by its own verdict; a WordPress job says nothing of the
     * sites it acts on.
     *
     * @var list<ProvisioningJobKind>
     */
    private const array REFUSED = [
        ProvisioningJobKind::CreateVps,
        ProvisioningJobKind::DestroyVps,
        ProvisioningJobKind::ReinstallVps,
        ProvisioningJobKind::ReinstallDedicated,
        ProvisioningJobKind::CreateHostingAccount,
        ProvisioningJobKind::InstallWordPress,
        ProvisioningJobKind::CopyWordPressSite,
        ProvisioningJobKind::PushWordPressToProduction,
        ProvisioningJobKind::ProvisionDedicated,
    ];

    #[Test]
    public function the_kinds_a_close_takes_are_exactly_the_ones_written_here(): void
    {
        $values = static fn (array $kinds): array => collect($kinds)->map(static fn (ProvisioningJobKind $kind): string => $kind->value)->sort()->values()->all();

        $this->assertSame($values(self::ACCEPTED), $values(CloseAJobWhoseServiceEnded::CLOSABLE_KINDS), 'CLOSABLE_KINDS moved: a kind added may leave a resource no list points to.');

        // Every kind is decided here, one way or the other: a new kind fails
        // this until someone writes down whether a close may take it.
        $this->assertSame($values(ProvisioningJobKind::cases()), $values([...self::ACCEPTED, ...self::REFUSED]), 'A job kind is neither accepted nor refused by this test.');
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function kindsAClosedDoesNotTake(): iterable
    {
        foreach (self::REFUSED as $kind) {
            yield $kind->value => [$kind];
        }
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function kindsACloseTakes(): iterable
    {
        foreach (self::ACCEPTED as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[Test]
    #[DataProvider('kindsACloseTakes')]
    public function each_kind_a_close_takes_is_closed_on_an_ended_service(ProvisioningJobKind $kind): void
    {
        $job = $this->jobInReview($kind, 'vps', ServiceStatus::Terminated);

        $this->actingAs($this->operator(Role::SuperAdmin))
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/close', ['evidence' => 'Service has ended.'])
            ->assertOk()
            ->assertJsonPath('data.status', ProvisioningJobStatus::Cancelled->value);
    }

    /**
     * @return iterable<string, array{ServiceStatus}>
     */
    public static function servicesThatHaveNotEnded(): iterable
    {
        foreach (ServiceStatus::cases() as $status) {
            if ($status !== ServiceStatus::Terminated) {
                yield $status->value => [$status];
            }
        }
    }

    #[Test]
    #[DataProvider('servicesThatHaveNotEnded')]
    public function a_job_on_a_service_that_has_not_ended_is_not_closed_whatever_its_state(ServiceStatus $status): void
    {
        // A suspended service has not ended: it can be lifted, and what the
        // job was doing still matters. Only `terminated` is an end.
        $job = $this->jobInReview(ProvisioningJobKind::Resize, 'vps', $status);

        $this->assertRefused($job, 'provisioning.close_service_not_ended');
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
