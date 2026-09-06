<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Infrastructure\IpamReservationReleaser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * A timed-out provisioning job is not a failed one, and its address must not be
 * treated as if it were.
 *
 * A failure means nothing was built: the address was never configured anywhere
 * and can go back to the pool at once. A timeout means the platform stopped
 * waiting — the provider may not have. The machine could be running right now
 * with this address on its interface. Returning it to the pool puts two
 * machines on one address, and the fault that reaches an operator looks like a
 * network problem rather than a control-plane one, so it gets investigated in
 * the wrong place for as long as it takes someone to notice the coincidence.
 */
final class TimedOutReleaseTest extends TestCase
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
    public function a_failed_job_returns_its_address_to_the_pool_immediately(): void
    {
        $jobId = $this->createProvisioningJob('failed');
        [$reservation] = $this->allocator->reserve($this->subnet, $jobId);

        $this->allocator->release($reservation, ReleaseReason::JobFailed);

        // Nothing was built, so nothing carries a history. Holding it back
        // would waste finite address space for no benefit.
        $address = $reservation->ipAddress()->first();
        $this->assertSame(IpAddressStatus::Available, $address->status);
        $this->assertNull($address->quarantined_until);
    }

    #[Test]
    public function a_timed_out_job_quarantines_its_address_instead(): void
    {
        $jobId = $this->createProvisioningJob('failed');
        [$reservation] = $this->allocator->reserve($this->subnet, $jobId);

        $this->allocator->release($reservation, ReleaseReason::ProvisioningTimedOut);

        $address = $reservation->ipAddress()->first();

        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertNotNull($address->quarantined_until);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);
    }

    #[Test]
    public function a_quarantined_address_is_not_handed_to_the_next_customer(): void
    {
        // The /29 has five usable hosts. Reserve all of them, release one on a
        // timeout, and the pool must behave as if it has four — not five.
        $reservations = [];
        for ($i = 0; $i < 5; $i++) {
            $jobId = $this->createProvisioningJob('failed');
            [$reservations[]] = $this->allocator->reserve($this->subnet, $jobId);
        }

        $this->allocator->release($reservations[0], ReleaseReason::ProvisioningTimedOut);

        $available = $this->subnet->addresses()
            ->where('status', IpAddressStatus::Available->value)
            ->count();

        $this->assertSame(0, $available, 'A timed-out address must not be immediately allocatable.');
    }

    #[Test]
    public function the_releaser_quarantines_rather_than_releases_on_a_timeout(): void
    {
        /*
         * The provisioning engine does not know IP addresses exist. It knows
         * only that a failed job holds reservations, and that the failure class
         * decides whether they are released or quarantined. This adapter is
         * where that decision meets the resource, so it is asserted at that
         * boundary rather than only inside the allocator.
         */
        $job = ProvisioningJob::factory()->create(['status' => 'failed']);
        [$reservation] = $this->allocator->reserve($this->subnet, (string) $job->getKey());

        $quarantined = app(IpamReservationReleaser::class)->quarantine($job, 'provider timed out');

        $this->assertSame(1, $quarantined);
        $this->assertSame(IpAddressStatus::Quarantined, $reservation->ipAddress()->first()->status);
    }

    #[Test]
    public function the_releaser_returns_addresses_on_an_ordinary_failure(): void
    {
        $job = ProvisioningJob::factory()->create(['status' => 'failed']);
        [$reservation] = $this->allocator->reserve($this->subnet, (string) $job->getKey());

        $released = app(IpamReservationReleaser::class)->release($job);

        $this->assertSame(1, $released);
        $this->assertSame(IpAddressStatus::Available, $reservation->ipAddress()->first()->status);
    }

    #[Test]
    public function releasing_is_idempotent_under_either_reason(): void
    {
        // The reaper, the engine's compensation step and an operator can all
        // arrive at the same reservation; the second must not undo the first.
        $job = ProvisioningJob::factory()->create(['status' => 'failed']);
        [$reservation] = $this->allocator->reserve($this->subnet, (string) $job->getKey());

        app(IpamReservationReleaser::class)->quarantine($job, 'provider timed out');
        app(IpamReservationReleaser::class)->release($job);

        // Quarantine stands: a later, gentler reason must not shorten it.
        $this->assertSame(IpAddressStatus::Quarantined, $reservation->ipAddress()->first()->status);
    }
}
