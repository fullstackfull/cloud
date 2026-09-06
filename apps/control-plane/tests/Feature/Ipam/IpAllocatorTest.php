<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReservationExpiredException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * The reserve → commit → release lifecycle.
 *
 * Every test here is about one property: an address is held by exactly one
 * thing at a time, and the transitions between holders are atomic.
 */
final class IpAllocatorTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    private IpAllocator $allocator;

    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(IpAllocator::class);

        // 198.51.100.8/29 — .8 network, .15 broadcast, .9 gateway, five hosts.
        $this->subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();

        app(SeedSubnetAddresses::class)->execute($this->subnet);
    }

    #[Test]
    public function it_reserves_an_available_address_and_holds_it(): void
    {
        $customer = Customer::factory()->create();
        $jobId = $this->createProvisioningJob();

        $reservations = $this->allocator->reserve($this->subnet, $jobId, $customer);

        $this->assertCount(1, $reservations);
        $reservation = $reservations[0];

        $this->assertSame($jobId, $reservation->provisioning_job_id);
        $this->assertSame($customer->id, $reservation->customer_id);
        $this->assertTrue($reservation->isLive());
        $this->assertFalse($reservation->hasElapsed());

        $address = IpAddress::query()->findOrFail($reservation->ip_address_id);
        $this->assertSame(IpAddressStatus::Reserved, $address->status);
        $this->assertSame(4, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function the_gateway_network_and_broadcast_addresses_are_never_allocated(): void
    {
        $jobId = $this->createProvisioningJob();

        // Take every allocatable address in the subnet in one go.
        $reservations = $this->allocator->reserve($this->subnet, $jobId, count: 5);

        $addresses = IpAddress::query()
            ->whereIn('id', array_map(static fn (IpReservation $r): string => $r->ip_address_id, $reservations))
            ->orderBy('address')
            ->pluck('address')
            ->all();

        $this->assertSame([
            '198.51.100.10', '198.51.100.11', '198.51.100.12', '198.51.100.13', '198.51.100.14',
        ], $addresses);

        $this->assertNotContains('198.51.100.8', $addresses, 'the network address was allocated');
        $this->assertNotContains('198.51.100.15', $addresses, 'the broadcast address was allocated');
        $this->assertNotContains('198.51.100.9', $addresses, 'the gateway was allocated');
    }

    #[Test]
    public function an_exhausted_subnet_throws_rather_than_returning_nothing(): void
    {
        $jobId = $this->createProvisioningJob();
        $this->allocator->reserve($this->subnet, $jobId, count: 5);

        try {
            $this->allocator->reserve($this->subnet, $this->createProvisioningJob());
            $this->fail('An exhausted subnet must throw, not return an empty result.');
        } catch (IpPoolExhaustedException $e) {
            $this->assertSame('ipam.pool_exhausted', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(0, $e->context()['available']);
            $this->assertSame('198.51.100.8/29', $e->context()['cidr']);
        }
    }

    #[Test]
    public function a_partially_satisfiable_request_is_refused_whole(): void
    {
        // Four free addresses, five asked for. Handing back four would build a
        // machine that is short an address and never notice.
        $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), count: 4);

        $this->expectException(IpPoolExhaustedException::class);

        try {
            $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), count: 5);
        } finally {
            $this->assertSame(1, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
            $this->assertSame(4, IpReservation::query()->live()->count());
        }
    }

    #[Test]
    public function a_rolled_back_reservation_leaves_the_address_available(): void
    {
        $jobId = $this->createProvisioningJob();

        try {
            DB::transaction(function () use ($jobId): void {
                $this->allocator->reserve($this->subnet, $jobId);

                // The caller fails after the address was chosen — an order
                // that could not be written, a hypervisor that refused the
                // request. The address must not leak out of the pool.
                throw new RuntimeException('the caller failed after reserving');
            });

            $this->fail('The surrounding transaction should have propagated the failure.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, IpReservation::query()->count());
        $this->assertSame(5, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function the_live_reservation_index_refuses_a_second_claim_on_one_address(): void
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        $caught = null;

        try {
            // A savepoint, so the unique violation does not poison the test's
            // own transaction — the point is the database's refusal, not the
            // application's.
            DB::transaction(fn (): IpReservation => IpReservation::create([
                'ip_address_id' => $reservation->ip_address_id,
                'provisioning_job_id' => $this->createProvisioningJob(),
                'expires_at' => now()->addHour(),
            ]));
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'The database must refuse a second live reservation on one address.');
        $this->assertStringContainsString('ip_reservations_live_idx', $caught->getMessage());
        $this->assertSame(1, IpReservation::query()->live()->where('ip_address_id', $reservation->ip_address_id)->count());
    }

    #[Test]
    public function a_released_reservation_frees_the_address_for_a_new_claim(): void
    {
        $first = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];
        $this->allocator->release($first, ReleaseReason::JobFailed);

        // The partial index only covers live rows, so the released one does
        // not stand in the way of the next claim on the same address.
        $second = IpReservation::create([
            'ip_address_id' => $first->ip_address_id,
            'provisioning_job_id' => $this->createProvisioningJob(),
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($second->exists);
        $this->assertSame(2, IpReservation::query()->where('ip_address_id', $first->ip_address_id)->count());
    }

    #[Test]
    public function committing_a_reservation_creates_an_assignment_and_closes_the_claim(): void
    {
        $customer = Customer::factory()->create();
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), $customer)[0];

        $assignment = $this->allocator->commit($reservation, serviceId: null, macAddress: 'aa-bb-cc-dd-ee-ff');

        $this->assertSame($reservation->ip_address_id, $assignment->ip_address_id);
        $this->assertSame($customer->id, $assignment->customer_id);
        // Stored in one canonical shape, so one NIC is never two strings.
        $this->assertSame('AA:BB:CC:DD:EE:FF', $assignment->mac_address);
        $this->assertTrue($assignment->isLive());

        $this->assertSame(IpAddressStatus::Assigned, IpAddress::query()->findOrFail($reservation->ip_address_id)->status);

        $reservation->refresh();
        $this->assertFalse($reservation->isLive());
        $this->assertSame(ReleaseReason::Committed, $reservation->released_reason);
    }

    #[Test]
    public function a_released_reservation_can_no_longer_be_committed(): void
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];
        $this->allocator->release($reservation, ReleaseReason::JobFailed);

        try {
            $this->allocator->commit($reservation);
            $this->fail('Committing a released reservation must throw.');
        } catch (ReservationExpiredException $e) {
            $this->assertSame('ipam.reservation_expired', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(ReleaseReason::JobFailed->value, $e->context()['released_reason']);
        }

        $this->assertSame(0, IpAssignment::query()->count());
    }

    #[Test]
    public function a_slow_job_may_still_commit_after_its_window_elapsed(): void
    {
        // A hypervisor that took an hour longer than expected is not a dead
        // job. Nothing reclaims on time alone, so while the reservation is
        // live the address is provably still this job's — refusing here would
        // strand a machine that is already configured with the address.
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), ttlSeconds: 60)[0];

        $this->travel(2)->hours();

        $this->assertTrue($reservation->fresh()?->hasElapsed());

        $assignment = $this->allocator->commit($reservation);

        $this->assertTrue($assignment->isLive());
        $this->assertSame(IpAddressStatus::Assigned, IpAddress::query()->findOrFail($reservation->ip_address_id)->status);
    }

    #[Test]
    public function committing_an_address_that_moved_on_is_refused(): void
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        // An operator takes the address out of service without touching the
        // reservation row.
        IpAddress::query()->whereKey($reservation->ip_address_id)->update([
            'status' => IpAddressStatus::Unavailable->value,
        ]);

        $this->expectException(AddressNotAllocatableException::class);

        $this->allocator->commit($reservation);
    }

    #[Test]
    public function a_malformed_mac_address_is_rejected_before_anything_is_written(): void
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        $this->expectException(InvalidIpAddressException::class);

        try {
            $this->allocator->commit($reservation, macAddress: 'not-a-mac');
        } finally {
            $this->assertSame(0, IpAssignment::query()->count());
            $this->assertTrue($reservation->fresh()?->isLive());
        }
    }

    #[Test]
    public function a_reservation_released_before_use_goes_straight_back_to_the_pool(): void
    {
        // It was never configured, never announced and never resolved, so
        // there is no reputation to inherit and no reason to quarantine it.
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        $released = $this->allocator->release($reservation, ReleaseReason::JobFailed);

        $this->assertFalse($released->isLive());
        $this->assertSame(ReleaseReason::JobFailed, $released->released_reason);

        $address = IpAddress::query()->findOrFail($reservation->ip_address_id);
        $this->assertSame(IpAddressStatus::Available, $address->status);
        $this->assertNull($address->quarantined_until);
        $this->assertSame(5, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function releasing_a_reservation_twice_keeps_the_first_reason(): void
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        $this->allocator->release($reservation, ReleaseReason::JobFailed);
        $second = $this->allocator->release($reservation, ReleaseReason::OperatorAction);

        $this->assertSame(ReleaseReason::JobFailed, $second->released_reason);
    }

    #[Test]
    public function reserving_from_a_pool_spans_its_subnets(): void
    {
        /** @var IpPool $pool */
        $pool = IpPool::query()->findOrFail($this->subnet->ip_pool_id);

        $second = Subnet::factory()
            ->for($pool)
            ->forBlock('203.0.113.0/29', gateway: '203.0.113.1')
            ->create();
        app(SeedSubnetAddresses::class)->execute($second);

        // Five hosts in each subnet; asking for eight has to cross the
        // boundary between them.
        $reservations = $this->allocator->reserve($pool, $this->createProvisioningJob(), count: 8);

        $this->assertCount(8, $reservations);

        $subnetIds = IpAddress::query()
            ->whereIn('id', array_map(static fn (IpReservation $r): string => $r->ip_address_id, $reservations))
            ->distinct()
            ->pluck('subnet_id')
            ->all();

        $this->assertCount(2, $subnetIds);
    }

    #[Test]
    public function a_retried_job_is_handed_the_address_it_already_holds(): void
    {
        // A provisioning job that fails on a retryable fault goes back on the
        // queue and runs from the top, so it asks for an address a second
        // time. Taking a second one strands the first: the reaper only
        // releases reservations whose job failed terminally, so once the retry
        // succeeds the abandoned address stays reserved for ever.
        $jobId = $this->createProvisioningJob('running');

        $first = $this->allocator->reserve($this->subnet, $jobId)[0];
        $second = $this->allocator->reserve($this->subnet, $jobId);

        $this->assertCount(1, $second);
        $this->assertSame($first->id, $second[0]->id);
        $this->assertSame($first->ip_address_id, $second[0]->ip_address_id);

        $this->assertSame(1, IpReservation::query()->live()->count());
        $this->assertSame(4, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function a_retried_job_asking_for_more_addresses_tops_up_rather_than_starting_over(): void
    {
        $jobId = $this->createProvisioningJob('running');

        $first = $this->allocator->reserve($this->subnet, $jobId)[0];
        $both = $this->allocator->reserve($this->subnet, $jobId, count: 2);

        $this->assertCount(2, $both);
        $this->assertSame($first->id, $both[0]->id, 'The address already held must be kept and reused.');
        $this->assertNotSame($first->id, $both[1]->id);

        $this->assertSame(2, IpReservation::query()->live()->count());
        $this->assertSame(3, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function one_job_holding_addresses_in_two_pools_keeps_them_apart(): void
    {
        // A VM with a public and a private NIC is one job asking two different
        // pools for an address. Reuse is keyed on the scope as well as the
        // job, or the second request would be answered with the first pool's
        // address and the machine would come up on the wrong segment.
        $private = IpPool::factory()->private()->create();
        $privateSubnet = Subnet::factory()->for($private)->forBlock('10.80.0.0/29', gateway: '10.80.0.1')->create();
        app(SeedSubnetAddresses::class)->execute($privateSubnet);

        $jobId = $this->createProvisioningJob('running');

        $public = $this->allocator->reserve($this->subnet, $jobId)[0];
        $internal = $this->allocator->reserve($privateSubnet, $jobId)[0];

        $this->assertNotSame($public->id, $internal->id);
        $this->assertSame(
            $privateSubnet->id,
            IpAddress::query()->findOrFail($internal->ip_address_id)->subnet_id,
        );

        // And each request is still idempotent within its own scope.
        $this->assertSame($public->id, $this->allocator->reserve($this->subnet, $jobId)[0]->id);
        $this->assertSame($internal->id, $this->allocator->reserve($privateSubnet, $jobId)[0]->id);
    }

    #[Test]
    public function a_deactivated_pool_offers_nothing_even_when_a_subnet_is_named(): void
    {
        // is_active on a pool is an operator's kill switch — the block is
        // being renumbered or handed back — and a switch that only works when
        // the caller happens to pass the pool is not a switch.
        IpPool::query()->whereKey($this->subnet->ip_pool_id)->update(['is_active' => false]);

        $this->expectException(IpPoolExhaustedException::class);

        try {
            $this->allocator->reserve($this->subnet->fresh(), $this->createProvisioningJob());
        } finally {
            $this->assertSame(0, IpReservation::query()->count());
        }
    }

    #[Test]
    public function a_management_address_is_never_handed_to_a_customer(): void
    {
        // A management address reaches the hypervisor and BMC control planes.
        // On a customer NIC it is a lateral-movement path into the platform.
        $pool = IpPool::factory()->management()->create();
        $subnet = Subnet::factory()->for($pool)->forBlock('10.99.0.0/29', gateway: '10.99.0.1')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $customer = Customer::factory()->create();

        try {
            $this->allocator->reserve($subnet, $this->createProvisioningJob(), $customer);
            $this->fail('A customer must not be given an address out of a management pool.');
        } catch (AddressNotAllocatableException $e) {
            $this->assertSame('management', $e->context()['scope']);
        }

        $this->assertSame(0, IpReservation::query()->count());

        // The platform itself still allocates out of it: a hypervisor needs a
        // management address, and it belongs to no customer.
        $this->assertCount(1, $this->allocator->reserve($subnet, $this->createProvisioningJob()));
    }

    #[Test]
    public function an_inactive_subnet_offers_nothing(): void
    {
        $this->subnet->update(['is_active' => false]);

        $this->expectException(IpPoolExhaustedException::class);

        $this->allocator->reserve($this->subnet, $this->createProvisioningJob());
    }
}
