<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Application\Queries\HeldQuarantineAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Domain\Services\IpCapacityReporter;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * The addresses waiting for a person, and the report that names them (F-12).
 *
 * A held quarantine ends only when somebody declares the machine carrying it
 * empty. The failure that makes that dangerous is the one nobody sees: a
 * machine left in maintenance for ever, and its addresses with it. So the
 * capacity report counts them, and lists which machine each one is waiting
 * for — and a list that names the wrong machine, or the wrong date, is worse
 * than none, because it sends an operator to the wrong rack.
 */
final class HeldQuarantineReportTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    private IpAllocator $allocator;

    private IpPool $pool;

    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(IpAllocator::class);
        $this->pool = IpPool::factory()->create(['slug' => 'held-public-v4']);
        $this->subnet = $this->subnetIn($this->pool, '198.51.100.0/28');
    }

    #[Test]
    public function the_list_names_the_machine_each_address_is_waiting_for_oldest_first(): void
    {
        $older = $this->chassis();
        $newer = $this->chassis();

        $a = $this->assign($this->subnet, $older);
        $b = $this->assign($this->subnet, $newer);

        $this->travel(-5)->days();
        $this->allocator->holdAssignment($a, ReleaseReason::ServiceTerminated);
        $this->travelBack();
        $this->allocator->holdAssignment($b, ReleaseReason::ServiceTerminated);

        $held = app(HeldQuarantineAddresses::class)->inPool($this->pool);

        $this->assertCount(2, $held);

        $this->assertSame((string) $a->ip_address_id, $held[0]['address_id']);
        $this->assertSame($older->getMorphClass(), $held[0]['holder_type']);
        $this->assertSame((string) $older->getKey(), $held[0]['holder_id']);
        $this->assertSame((string) $a->getKey(), $held[0]['assignment_id']);
        $this->assertSame(
            $a->refresh()->released_at?->toDateTimeString(),
            substr((string) $held[0]['held_since'], 0, 19),
        );

        $this->assertSame((string) $newer->getKey(), $held[1]['holder_id']);
    }

    #[Test]
    public function only_held_addresses_in_this_pool_are_listed(): void
    {
        $elsewhere = IpPool::factory()->create();
        $otherSubnet = $this->subnetIn($elsewhere, '192.0.2.0/28');

        $chassis = $this->chassis();

        $held = $this->assign($this->subnet, $chassis);
        $this->allocator->holdAssignment($held, ReleaseReason::ServiceTerminated);

        // On a clock already: capacity that comes back on its own.
        $clocked = $this->assign($this->subnet, $chassis);
        $this->allocator->releaseAssignment($clocked, ReleaseReason::ServiceTerminated);

        // Still live.
        $this->assign($this->subnet, $chassis);

        // Held, but in another pool.
        $foreign = $this->assign($otherSubnet, $chassis);
        $this->allocator->holdAssignment($foreign, ReleaseReason::ServiceTerminated);

        $listed = app(HeldQuarantineAddresses::class)->inPool($this->pool);

        $this->assertSame([(string) $held->ip_address_id], array_column($listed, 'address_id'));
        $this->assertSame(1, app(IpCapacityReporter::class)->forPool($this->pool)->quarantinedHeld);
        $this->assertSame(1, app(IpCapacityReporter::class)->forPool($this->pool)->quarantinedOnAClock());
    }

    #[Test]
    public function the_holder_is_the_latest_assignment_and_a_tie_goes_to_the_later_row(): void
    {
        /*
         * `assigned_at` is `timestamp(0)`: two assignments on one address in the
         * same second tie, and only the ULID says which came second. Built in
         * both insertion orders, with the expectation derived here rather than
         * from the query, so neither physical order nor the ULID's absence can
         * pass both halves.
         */
        $this->freezeTime();

        foreach ([false, true] as $laterFirst) {
            [$earlierId, $laterId] = $this->twoUlidsInOrder();
            $early = $this->chassis();
            $late = $this->chassis();
            $address = $this->freeAddressIn($this->subnet);

            $rows = [[$earlierId, $early], [$laterId, $late]];

            if ($laterFirst) {
                $rows = array_reverse($rows);
            }

            foreach ($rows as [$id, $holder]) {
                $this->historicAssignment($address, $holder, now(), now(), $id);
            }

            $this->holdByHand($address);

            $this->assertSame(
                1,
                IpAssignment::query()->where('ip_address_id', $address->getKey())->distinct()->count('assigned_at'),
                'The fixture did not produce a tie on assigned_at.',
            );

            $row = collect(app(HeldQuarantineAddresses::class)->inPool($this->pool))
                ->firstWhere('address_id', (string) $address->getKey());

            $this->assertSame($laterId, $row['assignment_id'] ?? null, 'The list named the wrong assignment.');
            $this->assertSame((string) $late->getKey(), $row['holder_id'] ?? null, 'The list named the wrong chassis.');
        }

        // And the timestamp outranks the id: a later assignment carrying the
        // lower id is still the later one.
        [$lowId, $highId] = $this->twoUlidsInOrder();
        $earlierHolder = $this->chassis();
        $laterHolder = $this->chassis();
        $address = $this->freeAddressIn($this->subnet);

        $this->historicAssignment($address, $earlierHolder, now()->subHour(), now()->subMinutes(30), $highId);
        $this->historicAssignment($address, $laterHolder, now()->subMinutes(10), now(), $lowId);
        $this->holdByHand($address);

        $row = collect(app(HeldQuarantineAddresses::class)->inPool($this->pool))
            ->firstWhere('address_id', (string) $address->getKey());

        $this->assertSame($lowId, $row['assignment_id'] ?? null, 'The id outranked the assignment time.');
    }

    #[Test]
    public function equal_release_stamps_are_listed_by_address_whichever_order_they_were_released_in(): void
    {
        /*
         * Equal `released_at` is the ordinary case, not an edge: `released_at`
         * is `timestamp(0)`, so a machine that hands back four addresses in one
         * decommission stamps all four in the same second. Two pools, released
         * in opposite orders — one tied pair can agree with an undecided sort by
         * accident; two pulling in opposite directions cannot both.
         */
        $this->freezeTime();

        $second = IpPool::factory()->create();
        $secondSubnet = $this->subnetIn($second, '192.0.2.0/28');

        foreach ([[$this->pool, $this->subnet, true], [$second, $secondSubnet, false]] as [$pool, $subnet, $highestFirst]) {
            $chassis = $this->chassis();
            $assignments = [$this->assign($subnet, $chassis), $this->assign($subnet, $chassis), $this->assign($subnet, $chassis)];

            usort($assignments, fn (IpAssignment $x, IpAssignment $y): int => strcmp(
                $this->addressOf($x),
                $this->addressOf($y),
            ) * ($highestFirst ? -1 : 1));

            foreach ($assignments as $assignment) {
                $this->allocator->holdAssignment($assignment, ReleaseReason::ServiceTerminated);
            }

            $listed = array_column(app(HeldQuarantineAddresses::class)->inPool($pool), 'address');
            $sorted = $listed;
            sort($sorted, SORT_STRING);

            $this->assertSame($sorted, $listed, 'Addresses released in the same second came back in no stated order.');
        }
    }

    #[Test]
    public function one_address_in_two_overlapping_subnets_is_still_ordered(): void
    {
        /*
         * The address is not unique at the scope this query works at: the
         * constraint is (subnet_id, address), and a pool holds many subnets.
         * RegisterSubnet refuses a new overlap, but the schema does not, so
         * one predating that check, or one written by the reference topology
         * loader or SubnetFactory — which is how this test writes it — still
         * puts one address on two rows. The row id is the last key, and the
         * rows are written higher id first so insertion order cannot pass.
         */
        $this->freezeTime();

        $overlap = $this->subnetIn($this->pool, '198.51.100.128/25', seed: false);
        $twin = $this->subnetIn($this->pool, '198.51.100.192/26', seed: false);

        [$lowId, $highId] = $this->twoUlidsInOrder();

        foreach ([[$highId, $twin], [$lowId, $overlap]] as [$id, $subnet]) {
            $address = new IpAddress;
            $address->forceFill([
                'id' => $id,
                'subnet_id' => $subnet->getKey(),
                'address' => '198.51.100.200',
                'ip_version' => IpVersion::V4,
                'status' => IpAddressStatus::Quarantined,
                'quarantine_reason' => ReleaseReason::ServiceTerminated,
            ])->save();

            $this->historicAssignment($address, $this->chassis(), now(), now());
        }

        $ids = array_column(
            array_values(array_filter(
                app(HeldQuarantineAddresses::class)->inPool($this->pool),
                static fn (array $row): bool => $row['address'] === '198.51.100.200',
            )),
            'address_id',
        );

        $this->assertSame([$lowId, $highId], $ids);
    }

    #[Test]
    public function an_address_with_nobody_behind_it_is_listed_last_and_unattributed(): void
    {
        $chassis = $this->chassis();
        $dated = $this->assign($this->subnet, $chassis);
        $this->allocator->holdAssignment($dated, ReleaseReason::ServiceTerminated);

        // Quarantined without a clock and with no assignment behind it: what a
        // hand edit, or a defect, leaves.
        $stranded = $this->freeAddressIn($this->subnet);
        $this->holdByHand($stranded);

        $held = app(HeldQuarantineAddresses::class)->inPool($this->pool);

        $this->assertSame(
            [(string) $dated->ip_address_id, (string) $stranded->getKey()],
            array_column($held, 'address_id'),
        );
        $this->assertNull($held[1]['holder_type']);
        $this->assertNull($held[1]['holder_id']);
        $this->assertNull($held[1]['held_since']);
        $this->assertNull($held[1]['assignment_id']);

        $this->artisan('ipam:capacity', ['--pool' => 'held-public-v4'])
            ->expectsOutputToContain('2 address(es) in held-public-v4 are waiting for a person')
            ->expectsOutputToContain('unattributed')
            ->expectsOutputToContain((string) $chassis->getKey());
    }

    #[Test]
    public function an_assignment_with_no_holder_type_is_reported_unattributed(): void
    {
        $address = $this->freeAddressIn($this->subnet);

        $assignment = new IpAssignment;
        $assignment->forceFill([
            'ip_address_id' => $address->getKey(),
            'assignable_type' => null,
            'assignable_id' => (string) Str::ulid(),
            'is_primary' => true,
            'assigned_at' => now()->subDay(),
            'released_at' => now(),
        ])->save();

        $this->holdByHand($address);

        $this->artisan('ipam:capacity', ['--pool' => 'held-public-v4'])
            ->expectsOutputToContain('unattributed');
    }

    #[Test]
    public function the_report_states_the_true_total_when_it_lists_fewer(): void
    {
        $pool = IpPool::factory()->create(['slug' => 'held-large']);
        $subnet = $this->subnetIn($pool, '10.77.0.0/25');

        DB::table('ip_addresses')
            ->where('subnet_id', $subnet->getKey())
            ->where('status', IpAddressStatus::Available->value)
            ->orderBy('address')
            ->limit(HeldQuarantineAddresses::DEFAULT_LIMIT + 1)
            ->pluck('id')
            ->each(static function (string $id): void {
                DB::table('ip_addresses')->where('id', $id)->update([
                    'status' => IpAddressStatus::Quarantined->value,
                    'quarantined_until' => null,
                    'quarantine_reason' => ReleaseReason::ServiceTerminated->value,
                ]);
            });

        $this->assertCount(
            HeldQuarantineAddresses::DEFAULT_LIMIT,
            app(HeldQuarantineAddresses::class)->inPool($pool),
        );

        $this->artisan('ipam:capacity', ['--pool' => 'held-large'])
            ->expectsOutputToContain(sprintf(
                '%d address(es) in held-large are waiting for a person',
                HeldQuarantineAddresses::DEFAULT_LIMIT + 1,
            ))
            ->expectsOutputToContain(sprintf('the %d longest-waiting', HeldQuarantineAddresses::DEFAULT_LIMIT));
    }

    private function subnetIn(IpPool $pool, string $cidr, bool $seed = true): Subnet
    {
        $subnet = Subnet::factory()->for($pool)->forBlock($cidr)->create();

        if ($seed) {
            app(SeedSubnetAddresses::class)->execute($subnet);
        }

        return $subnet;
    }

    private function chassis(): DedicatedServer
    {
        return DedicatedServer::factory()->inDatacenter(Datacenter::factory()->create())->create();
    }

    private function assign(Subnet $subnet, DedicatedServer $holder): IpAssignment
    {
        $reservation = $this->allocator->reserve($subnet, $this->createProvisioningJob())[0];

        return $this->allocator->commit($reservation, assignable: $holder);
    }

    private function addressOf(IpAssignment $assignment): string
    {
        return IpAddress::query()->findOrFail($assignment->ip_address_id)->address;
    }

    private function freeAddressIn(Subnet $subnet): IpAddress
    {
        return IpAddress::query()
            ->where('subnet_id', $subnet->getKey())
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
