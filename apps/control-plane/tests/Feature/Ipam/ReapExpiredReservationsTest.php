<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Application\Actions\ReapExpiredReservations;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * The reaper's single most important property is what it refuses to do.
 *
 * Releasing a reservation whose job is still running hands a live address to a
 * second customer, and the first job — which was merely slow — then configures
 * the same address on another machine. That is the collision the whole module
 * exists to prevent, and a background job is the easiest place to recreate it.
 */
final class ReapExpiredReservationsTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    private IpAllocator $allocator;

    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(IpAllocator::class);
        $this->subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($this->subnet);
    }

    #[Test]
    public function it_releases_a_reservation_whose_job_failed(): void
    {
        $jobId = $this->createProvisioningJob('running');
        $reservation = $this->allocator->reserve($this->subnet, $jobId)[0];

        $this->markJob($jobId, 'failed');

        $released = app(ReapExpiredReservations::class)->execute();

        $this->assertCount(1, $released);
        $this->assertSame($reservation->id, $released[0]->id);
        $this->assertSame(ReleaseReason::JobFailed, $released[0]->released_reason);

        $this->assertFalse($reservation->fresh()?->isLive());
        $this->assertSame(
            IpAddressStatus::Available,
            IpAddress::query()->findOrFail($reservation->ip_address_id)->status,
        );
    }

    #[Test]
    public function it_does_not_release_a_reservation_whose_job_is_still_running_however_old_it_is(): void
    {
        $jobId = $this->createProvisioningJob('running');
        $reservation = $this->allocator->reserve($this->subnet, $jobId, ttlSeconds: 60)[0];

        // A month past its window. A slow hypervisor, a storage array in the
        // middle of a rebuild and a provider rate-limiting us all look exactly
        // like this, and in every one of those cases the address is about to
        // be written onto a NIC.
        $this->travel(30)->days();

        $this->assertTrue($reservation->fresh()?->hasElapsed());

        $released = app(ReapExpiredReservations::class)->execute();

        $this->assertSame([], $released, 'A running job must keep its address however long it takes.');
        $this->assertTrue($reservation->fresh()?->isLive());
        $this->assertSame(
            IpAddressStatus::Reserved,
            IpAddress::query()->findOrFail($reservation->ip_address_id)->status,
        );
    }

    #[Test]
    public function a_timed_out_job_keeps_its_address(): void
    {
        // A timeout means the platform stopped waiting, not that the provider
        // stopped working — the machine may exist with this address already
        // configured. It goes to an operator, not to the next customer.
        //
        // The status written here is the one the provisioning engine actually
        // settles a timed-out job in (ProvisioningJobStateMachine: running →
        // needs_review is where a timeout lands). Marking the job with an
        // invented status string instead would make this test pass whatever
        // the reaper's list contained, including a list that reaped timeouts.
        $jobId = $this->createProvisioningJob('running');
        $reservation = $this->allocator->reserve($this->subnet, $jobId)[0];

        $this->markJob($jobId, ProvisioningJobStatus::NeedsReview->value);
        $this->travel(30)->days();

        $this->assertSame([], app(ReapExpiredReservations::class)->execute());
        $this->assertTrue($reservation->fresh()?->isLive());
    }

    #[Test]
    public function the_terminal_failure_list_names_statuses_the_provisioning_engine_actually_uses(): void
    {
        /*
         * The list is strings, because provisioning_jobs belongs to another
         * module and IPAM must not depend on its classes. The cost of that is
         * that a renamed or misspelled status is not a compile error — the
         * subquery simply matches nothing and the reaper silently stops
         * reaping, leaking an address per failed build until somebody notices
         * the pool is full. This is the test that turns that into a failure.
         */
        foreach (ReapExpiredReservations::TERMINAL_FAILURE_STATUSES as $status) {
            $this->assertNotNull(
                ProvisioningJobStatus::tryFrom($status),
                sprintf('"%s" is not a provisioning job status; the reaper would never match it.', $status),
            );
        }

        // And the statuses that must never be in it: a job that is not
        // finished, one that finished successfully, and above all one that
        // stopped because nobody can tell whether the machine exists.
        foreach ([
            ProvisioningJobStatus::Queued,
            ProvisioningJobStatus::Running,
            ProvisioningJobStatus::Succeeded,
            ProvisioningJobStatus::NeedsReview,
        ] as $status) {
            $this->assertNotContains(
                $status->value,
                ReapExpiredReservations::TERMINAL_FAILURE_STATUSES,
                sprintf('Reaping a "%s" job would hand a live address to a second customer.', $status->value),
            );
        }
    }

    #[Test]
    public function a_job_waiting_for_review_keeps_its_address(): void
    {
        /*
         * needs_review is settled — no worker will pick it up again — but it
         * is not a failure. The engine stopped there precisely because it
         * could not tell whether the resource exists, so the machine may be
         * running right now with this address configured on it. Reclaiming it
         * would hand a live address to the next customer.
         */
        $jobId = $this->createProvisioningJob('running');
        $reservation = $this->allocator->reserve($this->subnet, $jobId)[0];

        $this->markJob($jobId, 'needs_review');
        $this->travel(30)->days();

        $this->assertSame([], app(ReapExpiredReservations::class)->execute());
        $this->assertTrue($reservation->fresh()?->isLive());
        $this->assertSame(
            IpAddressStatus::Reserved,
            IpAddress::query()->findOrFail($reservation->ip_address_id)->status,
        );
    }

    #[Test]
    public function a_reservation_with_no_job_is_left_for_a_human(): void
    {
        // The job row is gone. That is not evidence that the machine it built
        // is gone, and a background loop must not guess.
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];
        $reservation->forceFill(['provisioning_job_id' => null])->save();

        $this->travel(30)->days();

        $this->assertSame([], app(ReapExpiredReservations::class)->execute());
        $this->assertTrue($reservation->fresh()?->isLive());
    }

    #[Test]
    #[DataProvider('terminalStatuses')]
    public function every_terminal_failure_status_releases_the_address(string $status): void
    {
        $jobId = $this->createProvisioningJob('running');
        $reservation = $this->allocator->reserve($this->subnet, $jobId)[0];

        $this->markJob($jobId, $status);

        $this->assertCount(1, app(ReapExpiredReservations::class)->execute());
        $this->assertFalse($reservation->fresh()?->isLive());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function terminalStatuses(): array
    {
        return array_reduce(
            ReapExpiredReservations::TERMINAL_FAILURE_STATUSES,
            static function (array $cases, string $status): array {
                $cases[$status] = [$status];

                return $cases;
            },
            [],
        );
    }

    #[Test]
    public function a_run_is_bounded_so_a_backlog_is_worked_off_in_passes(): void
    {
        $jobIds = [];

        foreach (range(1, 3) as $ignored) {
            $jobId = $this->createProvisioningJob('running');
            $this->allocator->reserve($this->subnet, $jobId);
            $jobIds[] = $jobId;
        }

        DB::table('provisioning_jobs')->whereIn('id', $jobIds)->update(['status' => 'failed']);

        $this->assertCount(2, app(ReapExpiredReservations::class)->execute(limit: 2));
        $this->assertCount(1, app(ReapExpiredReservations::class)->execute(limit: 2));
        $this->assertSame(0, IpReservation::query()->live()->count());
    }

    #[Test]
    public function an_already_released_reservation_is_not_reaped_twice(): void
    {
        $jobId = $this->createProvisioningJob('failed');
        $reservation = $this->allocator->reserve($this->subnet, $jobId)[0];
        $this->allocator->release($reservation, ReleaseReason::OperatorAction);

        $this->assertSame([], app(ReapExpiredReservations::class)->execute());
        $this->assertSame(ReleaseReason::OperatorAction, $reservation->fresh()?->released_reason);
    }

    private function markJob(string $jobId, string $status): void
    {
        DB::table('provisioning_jobs')->where('id', $jobId)->update([
            'status' => $status,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
