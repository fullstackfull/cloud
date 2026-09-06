<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpCapacityReporter;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

final class IpCapacityReporterTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    #[Test]
    public function it_counts_every_status_in_a_subnet(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $this->setStatus($subnet, '198.51.100.10', IpAddressStatus::Reserved);
        $this->setStatus($subnet, '198.51.100.11', IpAddressStatus::Assigned);
        $this->setStatus($subnet, '198.51.100.12', IpAddressStatus::Quarantined);

        $snapshot = app(IpCapacityReporter::class)->forSubnet($subnet);

        $this->assertSame('subnet', $snapshot->scopeType);
        $this->assertSame('198.51.100.8/29', $snapshot->label);
        $this->assertSame(8, $snapshot->total);
        $this->assertSame(2, $snapshot->available);
        $this->assertSame(1, $snapshot->reserved);
        $this->assertSame(1, $snapshot->assigned);
        $this->assertSame(1, $snapshot->quarantined);
        // Network, broadcast and gateway.
        $this->assertSame(3, $snapshot->unavailable);

        // Two of the five allocatable addresses are spoken for. Reporting
        // against all eight would say 25% and hide how close this subnet is
        // to full.
        $this->assertSame(0.4, $snapshot->utilisation());
    }

    #[Test]
    public function runway_is_measured_from_the_rate_addresses_are_actually_consumed(): void
    {
        $subnet = Subnet::factory()->forBlock('10.60.0.0/24', gateway: '10.60.0.1')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        // Fourteen addresses assigned over the last fourteen days: one a day.
        $addresses = IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->available()
            ->orderBy('address')
            ->limit(14)
            ->get();

        foreach ($addresses as $offset => $address) {
            $address->forceFill(['status' => IpAddressStatus::Assigned])->save();

            IpAssignment::factory()->create([
                'ip_address_id' => $address->id,
                'assigned_at' => now()->subDays($offset),
            ]);
        }

        $snapshot = app(IpCapacityReporter::class)->forSubnet($subnet);

        // 256 addresses, less network, broadcast, gateway and the fourteen
        // just assigned.
        $this->assertSame(239, $snapshot->available);
        $this->assertSame(1.0, $snapshot->allocationsPerDay);
        $this->assertSame(239.0, $snapshot->runwayDays);

        // Eight months of headroom is comfortable; if demand were a hundred a
        // day the same 93% free would be two days, which is why the question
        // is asked in days rather than in percent.
        $this->assertFalse($snapshot->needsMoreSpace(leadTimeDays: 30));
        $this->assertTrue($snapshot->needsMoreSpace(leadTimeDays: 300));
    }

    #[Test]
    public function a_scope_with_no_demand_reports_no_runway_rather_than_a_reassuring_number(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $snapshot = app(IpCapacityReporter::class)->forSubnet($subnet);

        $this->assertSame(0.0, $snapshot->allocationsPerDay);
        $this->assertNull($snapshot->runwayDays);
        $this->assertFalse($snapshot->needsMoreSpace());
    }

    #[Test]
    public function a_full_subnet_needs_space_whatever_the_rate_is(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->available()
            ->update(['status' => IpAddressStatus::Assigned->value]);

        $snapshot = app(IpCapacityReporter::class)->forSubnet($subnet);

        $this->assertSame(0, $snapshot->available);
        $this->assertNull($snapshot->runwayDays);
        $this->assertTrue($snapshot->needsMoreSpace());
        $this->assertSame(1.0, $snapshot->utilisation());
    }

    #[Test]
    public function a_pool_aggregates_its_subnets_but_still_reports_them_one_by_one(): void
    {
        $pool = IpPool::factory()->create();

        $healthy = Subnet::factory()->for($pool)->forBlock('10.70.0.0/24', gateway: '10.70.0.1')->create();
        $full = Subnet::factory()->for($pool)->forBlock('10.71.0.0/29', gateway: '10.71.0.1')->create();

        app(SeedSubnetAddresses::class)->execute($healthy);
        app(SeedSubnetAddresses::class)->execute($full);

        IpAddress::query()
            ->where('subnet_id', $full->id)
            ->available()
            ->update(['status' => IpAddressStatus::Assigned->value]);

        $poolSnapshot = app(IpCapacityReporter::class)->forPool($pool);

        $this->assertSame('pool', $poolSnapshot->scopeType);
        $this->assertSame(264, $poolSnapshot->total);
        $this->assertSame(253, $poolSnapshot->available);

        /*
         * The pool looks healthy and cannot place a service in 10.71.0.0/29.
         * Address space is only fungible inside a subnet — a VM's netmask and
         * gateway come from the subnet it sits in — so the per-subnet
         * breakdown is the one that answers "can we sell this".
         */
        $breakdown = app(IpCapacityReporter::class)->forPoolSubnets($pool);

        $this->assertCount(2, $breakdown);
        $this->assertSame(253, $breakdown[0]->available);
        $this->assertSame(0, $breakdown[1]->available);
        $this->assertTrue($breakdown[1]->needsMoreSpace());
    }

    #[Test]
    public function quarantined_addresses_are_counted_apart_from_free_ones(): void
    {
        // Quarantined space is real capacity that is deliberately withdrawn.
        // Counting it as free would make a pool look like it has room it
        // cannot hand out for another week.
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->available()
            ->limit(3)
            ->update([
                'status' => IpAddressStatus::Quarantined->value,
                'quarantined_until' => now()->addDays(7),
                'quarantine_reason' => ReleaseReason::Abuse->value,
            ]);

        $snapshot = app(IpCapacityReporter::class)->forSubnet($subnet);

        $this->assertSame(2, $snapshot->available);
        $this->assertSame(3, $snapshot->quarantined);
        $this->assertSame(8, $snapshot->total);
    }

    private function setStatus(Subnet $subnet, string $address, IpAddressStatus $status): void
    {
        IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->where('address', $address)
            ->update(['status' => $status->value]);
    }
}
