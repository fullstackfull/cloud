<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * A held quarantine, and the clock that ends it (F-12).
 *
 * An address released from a physical machine is still configured on that
 * machine until somebody erases its disks, so it is held — quarantined with no
 * expiry — rather than put on the pool's clock. The clock starts when the
 * machine is declared empty, and it must start for exactly the addresses that
 * machine was the last to hold.
 */
final class HeldQuarantineClockTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    private IpAllocator $allocator;

    private IpPool $pool;

    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(IpAllocator::class);
        $this->pool = IpPool::factory()->quarantineDays(7)->create();
        $this->subnet = Subnet::factory()->for($this->pool)->forBlock('198.51.100.0/28', gateway: '198.51.100.1')->create();
        app(SeedSubnetAddresses::class)->execute($this->subnet);
    }

    #[Test]
    public function a_held_release_ends_the_assignment_and_starts_no_clock(): void
    {
        $chassis = $this->chassis();
        $assignment = $this->assign($chassis);

        ReverseDnsRecord::query()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.departing.example',
            'status' => ReverseDnsStatus::Active,
        ]);

        $this->allocator->holdAssignment($assignment, ReleaseReason::ServiceTerminated);

        $this->assertNotNull($assignment->refresh()->released_at);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertSame(ReleaseReason::ServiceTerminated, $address->quarantine_reason);
        $this->assertNull($address->quarantined_until);

        $this->assertSame(
            ReverseDnsStatus::Removing,
            ReverseDnsRecord::query()->where('ip_address_id', $address->getKey())->sole()->status,
        );

        $this->travel(90)->days();
        $this->assertSame(0, app(ReleaseQuarantinedAddresses::class)->execute());
    }

    #[Test]
    public function the_clock_starts_for_the_holder_named_and_nobody_else(): void
    {
        $mine = $this->chassis();
        $theirs = $this->chassis();

        $a = $this->assign($mine);
        $b = $this->assign($theirs);

        $this->allocator->holdAssignment($a, ReleaseReason::ServiceTerminated);
        $this->allocator->holdAssignment($b, ReleaseReason::ServiceTerminated);

        $this->assertSame(1, $this->allocator->startHeldQuarantines($mine));

        $this->assertSame(
            now()->addDays(7)->toDateString(),
            IpAddress::query()->findOrFail($a->ip_address_id)->quarantined_until?->toDateString(),
        );
        $this->assertNull(IpAddress::query()->findOrFail($b->ip_address_id)->quarantined_until);
    }

    #[Test]
    public function an_address_the_holder_once_had_is_not_its_to_start(): void
    {
        /*
         * The address was on this chassis once, went round the pool, and is
         * now held for another machine. Returning the first chassis to stock
         * must not start a clock on an address the second one is still
         * carrying: only the latest assignment names the holder.
         */
        $old = $this->chassis();
        $current = $this->chassis();

        $address = $this->freeAddress();
        $this->historicAssignment($address, $old, now()->subDays(40), releasedAt: now()->subDays(30));
        $this->historicAssignment($address, $current, now()->subDays(10), releasedAt: now()->subDay());
        $this->holdByHand($address);

        $this->assertSame(0, $this->allocator->startHeldQuarantines($old));
        $this->assertNull($address->refresh()->quarantined_until);

        $this->assertSame(1, $this->allocator->startHeldQuarantines($current));
        $this->assertNotNull($address->refresh()->quarantined_until);
    }

    #[Test]
    public function a_tie_on_the_assignment_timestamp_is_broken_rather_than_ignored(): void
    {
        /*
         * `assigned_at` is `timestamp(0)`, so two assignments on one address in
         * the same second tie. The ULID breaks the tie: it is generated in
         * order, and the later row is the later holder. Both directions are
         * built, so no accident of insertion order can satisfy both.
         */
        $this->freezeTime();

        foreach ([false, true] as $laterFirst) {
            [$earlierId, $laterId] = $this->twoUlidsInOrder();
            [$firstInserted, $secondInserted] = $laterFirst ? [$laterId, $earlierId] : [$earlierId, $laterId];

            $early = $this->chassis();
            $late = $this->chassis();
            $address = $this->freeAddress();

            $holders = [$earlierId => $early, $laterId => $late];

            $this->historicAssignment($address, $holders[$firstInserted], now(), now(), id: $firstInserted);
            $this->historicAssignment($address, $holders[$secondInserted], now(), now(), id: $secondInserted);
            $this->holdByHand($address);

            // The control: the tie actually formed.
            $stamps = IpAssignment::query()->where('ip_address_id', $address->getKey())->pluck('assigned_at')
                ->map(static fn ($at): string => (string) $at)->unique();
            $this->assertCount(1, $stamps, 'The fixture did not produce a tie on assigned_at.');

            $this->assertSame(0, $this->allocator->startHeldQuarantines($early), 'The older holder started the clock.');
            $this->assertSame(1, $this->allocator->startHeldQuarantines($late), 'The latest holder could not.');
        }
    }

    #[Test]
    public function a_clock_already_running_is_not_restarted(): void
    {
        $chassis = $this->chassis();
        $assignment = $this->assign($chassis);
        $this->allocator->holdAssignment($assignment, ReleaseReason::ServiceTerminated);

        $this->assertSame(1, $this->allocator->startHeldQuarantines($chassis));
        $first = IpAddress::query()->findOrFail($assignment->ip_address_id)->quarantined_until;

        $this->travel(3)->days();

        $this->assertSame(0, $this->allocator->startHeldQuarantines($chassis));
        $this->assertEquals($first, IpAddress::query()->findOrFail($assignment->ip_address_id)->quarantined_until);
    }

    #[Test]
    public function an_abuse_hold_gets_the_abuse_window_when_its_clock_starts(): void
    {
        $chassis = $this->chassis();
        $assignment = $this->assign($chassis);
        $this->allocator->holdAssignment($assignment, ReleaseReason::Abuse);

        $this->allocator->startHeldQuarantines($chassis);

        $this->assertSame(
            now()->addDays(7 * IpPool::ABUSE_QUARANTINE_MULTIPLIER)->toDateString(),
            IpAddress::query()->findOrFail($assignment->ip_address_id)->quarantined_until?->toDateString(),
        );
    }

    private function chassis(): DedicatedServer
    {
        return DedicatedServer::factory()->inDatacenter(Datacenter::factory()->create())->create();
    }

    private function assign(DedicatedServer $holder): IpAssignment
    {
        $reservation = $this->allocator->reserve($this->subnet, $this->createProvisioningJob())[0];

        return $this->allocator->commit($reservation, assignable: $holder);
    }

    private function freeAddress(): IpAddress
    {
        return IpAddress::query()
            ->where('subnet_id', $this->subnet->getKey())
            ->where('status', IpAddressStatus::Available->value)
            ->orderBy('address')
            ->firstOrFail();
    }

    private function historicAssignment(
        IpAddress $address,
        DedicatedServer $holder,
        mixed $assignedAt,
        mixed $releasedAt,
        ?string $id = null,
    ): IpAssignment {
        $assignment = new IpAssignment;
        $assignment->forceFill(array_filter([
            'id' => $id,
            'ip_address_id' => $address->getKey(),
            'assignable_type' => $holder->getMorphClass(),
            'assignable_id' => $holder->getKey(),
            'is_primary' => true,
            'assigned_at' => $assignedAt,
            'released_at' => $releasedAt,
        ], static fn (mixed $value): bool => $value !== null))->save();

        return $assignment;
    }

    private function holdByHand(IpAddress $address): void
    {
        $address->forceFill([
            'status' => IpAddressStatus::Quarantined,
            'quarantined_until' => null,
            'quarantine_reason' => ReleaseReason::ServiceTerminated,
        ])->save();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function twoUlidsInOrder(): array
    {
        $ids = [strtolower((string) Str::ulid()), strtolower((string) Str::ulid())];
        sort($ids, SORT_STRING);

        return [$ids[0], $ids[1]];
    }
}
