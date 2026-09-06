<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * What happens to an address after its customer is gone.
 *
 * The property under test is that a released address is not immediately
 * reusable. It keeps arriving at its old destination for days — cached DNS,
 * third-party allow-lists, abuse reports written about last week's traffic —
 * and the next customer must not inherit any of it.
 */
final class QuarantineLifecycleTest extends TestCase
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
    public function a_released_address_is_quarantined_rather_than_immediately_available(): void
    {
        $assignment = $this->assignOneAddress();

        $this->allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);

        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertFalse($address->isAllocatable());
        $this->assertSame(ReleaseReason::ServiceTerminated, $address->quarantine_reason);
        $this->assertNotNull($address->quarantined_until);
        // The pool's window is seven days.
        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $address->quarantined_until->toDateString(),
        );

        // And it is genuinely out of circulation: only the four untouched
        // hosts remain allocatable.
        $this->assertSame(4, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function an_abuse_release_sits_out_longer_and_stays_flagged(): void
    {
        $assignment = $this->assignOneAddress();

        $this->allocator->releaseAssignment($assignment, ReleaseReason::Abuse);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);

        $this->assertSame(ReleaseReason::Abuse, $address->quarantine_reason);
        $this->assertSame(
            // Four times the pool's window: blocklist entries and takedown
            // notices about the traffic arrive latest of all.
            now()->addDays(7 * IpPool::ABUSE_QUARANTINE_MULTIPLIER)->toDateString(),
            $address->quarantined_until->toDateString(),
        );
    }

    #[Test]
    public function the_pool_owns_the_quarantine_window(): void
    {
        // Private space has no external reputation to inherit, so it recycles
        // in a day rather than a week.
        $pool = IpPool::factory()->private()->create();
        $subnet = Subnet::factory()->for($pool)->forBlock('10.90.0.0/29', gateway: '10.90.0.1')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $reservation = $this->allocator->reserve($subnet, $this->createProvisioningJob())[0];
        $assignment = $this->allocator->commit($reservation);

        $this->allocator->releaseAssignment($assignment, ReleaseReason::CustomerRequest);

        $this->assertSame(
            now()->addDay()->toDateString(),
            IpAddress::query()->findOrFail($assignment->ip_address_id)->quarantined_until->toDateString(),
        );
    }

    #[Test]
    public function quarantine_expiry_returns_the_address_to_the_pool(): void
    {
        $assignment = $this->assignOneAddress();
        $this->allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        // Six days in, the window has not elapsed and the sweeper leaves it.
        $this->travel(6)->days();
        $this->assertSame(0, app(ReleaseQuarantinedAddresses::class)->execute());
        $this->assertSame(
            IpAddressStatus::Quarantined,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->status,
        );

        // Two days later it has.
        $this->travel(2)->days();
        $this->assertSame(1, app(ReleaseQuarantinedAddresses::class)->execute());

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
        $this->assertSame(IpAddressStatus::Available, $address->status);
        // The reason is cleared with the status: a leftover "abuse" on an
        // available address reads as a warning about the wrong customer.
        $this->assertNull($address->quarantined_until);
        $this->assertNull($address->quarantine_reason);
        $this->assertSame(5, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function a_quarantined_address_with_no_window_is_never_swept_up(): void
    {
        // Something set the status without setting the expiry. The safe
        // reading of an unknown quarantine is "still serving it".
        IpAddress::factory()
            ->for($this->subnet)
            ->create([
                'address' => '198.51.100.99',
                'status' => IpAddressStatus::Quarantined,
                'quarantined_until' => null,
            ]);

        $this->travel(400)->days();

        $this->assertSame(0, app(ReleaseQuarantinedAddresses::class)->execute());
    }

    #[Test]
    public function an_address_taken_out_of_service_is_not_resurrected_by_a_release(): void
    {
        // An operator withdraws an address while it is still assigned: the
        // block is being renumbered, or the upstream has withdrawn the route.
        // Quarantining it would put it on the sweeper's list, and a week later
        // it would be handed to a new customer as if it were good.
        $assignment = $this->assignOneAddress();

        IpAddress::query()
            ->whereKey($assignment->ip_address_id)
            ->update(['status' => IpAddressStatus::Unavailable->value]);

        $this->allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
        $this->assertSame(IpAddressStatus::Unavailable, $address->status);
        $this->assertNull($address->quarantined_until);

        // The assignment is still closed — the customer no longer holds it —
        // and the sweeper never sees the address at all.
        $this->assertNotNull($assignment->fresh()?->released_at);

        $this->travel(400)->days();
        app(ReleaseQuarantinedAddresses::class)->execute();

        $this->assertSame(
            IpAddressStatus::Unavailable,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->status,
            'An address an operator withdrew must not return to the pool on its own.',
        );
    }

    #[Test]
    public function releasing_an_assignment_marks_the_previous_tenants_ptr_for_withdrawal(): void
    {
        /*
         * Quarantine exists so the next customer does not inherit the last
         * one's reputation, and a published PTR is that reputation at its most
         * literal: until it is withdrawn, every reverse lookup of the address
         * still answers with the previous customer's hostname.
         */
        $assignment = $this->assignOneAddress();

        $record = ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.previous-tenant.test',
            'status' => ReverseDnsStatus::Active,
        ]);

        $this->allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        $this->assertSame(ReverseDnsStatus::Removing, $record->fresh()?->status);
    }

    #[Test]
    public function assignment_history_survives_release_and_reassignment(): void
    {
        // "Who held 198.51.100.10 on the 4th of March" is the first question
        // asked when an abuse report or a court order arrives, and it is
        // unanswerable from a table that only keeps the present.
        $first = $this->assignOneAddress();
        $addressId = $first->ip_address_id;
        $firstCustomerId = $first->customer_id;

        $this->allocator->releaseAssignment($first, ReleaseReason::ServiceTerminated);

        $this->travel(8)->days();
        app(ReleaseQuarantinedAddresses::class)->execute();

        // The same address is now given to somebody else.
        $secondCustomer = Customer::factory()->create();
        $reservation = $this->reserveSpecificAddress($addressId, $secondCustomer);
        $second = $this->allocator->commit($reservation);

        $this->assertSame($addressId, $second->ip_address_id);

        $history = IpAssignment::query()
            ->where('ip_address_id', $addressId)
            ->orderBy('assigned_at')
            ->get();

        $this->assertCount(2, $history, 'The earlier assignment must not have been deleted or overwritten.');
        $this->assertSame($firstCustomerId, $history[0]->customer_id);
        $this->assertNotNull($history[0]->released_at);
        $this->assertSame($secondCustomer->id, $history[1]->customer_id);
        $this->assertNull($history[1]->released_at);
    }

    #[Test]
    public function releasing_an_assignment_twice_does_not_extend_the_quarantine(): void
    {
        $assignment = $this->assignOneAddress();

        $released = $this->allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);
        $firstReleasedAt = $released->released_at;

        $this->travel(1)->day();
        $again = $this->allocator->releaseAssignment($assignment, ReleaseReason::Abuse);

        $this->assertEquals($firstReleasedAt, $again->released_at);
        $this->assertSame(
            ReleaseReason::ServiceTerminated,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->quarantine_reason,
        );
    }

    private function assignOneAddress(): IpAssignment
    {
        $customer = Customer::factory()->create();
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), $customer)[0];

        return $this->allocator->commit($reservation, serviceId: null, macAddress: '52:54:00:12:34:56');
    }

    /**
     * Reserve one particular address, by taking everything before it out of
     * the way — the allocator deliberately offers no "give me this one".
     */
    private function reserveSpecificAddress(string $addressId, Customer $customer): IpReservation
    {
        IpAddress::query()
            ->where('subnet_id', $this->subnet->id)
            ->whereKeyNot($addressId)
            ->where('status', IpAddressStatus::Available->value)
            ->update(['status' => IpAddressStatus::Unavailable->value]);

        return $this->allocator->reserve($this->subnet, $this->createProvisioningJob(), $customer)[0];
    }
}
