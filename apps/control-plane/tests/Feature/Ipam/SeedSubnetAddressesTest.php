<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeedSubnetAddressesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_a_slash_29_produces_eight_rows_with_three_of_them_unallocatable(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();

        $result = app(SeedSubnetAddresses::class)->execute($subnet);

        $this->assertSame(8, $result->inserted);
        $this->assertSame(8, $result->total);
        // Eight addresses, less network, broadcast and gateway.
        $this->assertSame(5, $result->allocatable);
        $this->assertSame(5, IpAddress::query()->where('subnet_id', $subnet->id)->available()->count());

        $statuses = IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->pluck('status', 'address')
            ->map(static fn (IpAddressStatus $status): string => $status->value)
            ->all();

        $this->assertSame(IpAddressStatus::Unavailable->value, $statuses['198.51.100.8'], 'the network address');
        $this->assertSame(IpAddressStatus::Unavailable->value, $statuses['198.51.100.15'], 'the broadcast address');
        $this->assertSame(IpAddressStatus::Unavailable->value, $statuses['198.51.100.9'], 'the gateway');

        foreach (['198.51.100.10', '198.51.100.11', '198.51.100.12', '198.51.100.13', '198.51.100.14'] as $host) {
            $this->assertSame(IpAddressStatus::Available->value, $statuses[$host]);
        }
    }

    #[Test]
    public function re_running_the_seed_adds_only_what_is_missing(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->create();
        $action = app(SeedSubnetAddresses::class);

        $action->execute($subnet);

        // An operator deletes one row by hand, then re-runs the seed because
        // they are not sure the first run finished.
        IpAddress::query()->where('subnet_id', $subnet->id)->where('address', '198.51.100.12')->delete();

        $second = $action->execute($subnet);

        $this->assertSame(1, $second->inserted);
        $this->assertSame(8, $second->total);
        $this->assertSame(8, IpAddress::query()->where('subnet_id', $subnet->id)->count());
    }

    #[Test]
    public function a_second_run_is_a_no_op_and_does_not_disturb_allocated_addresses(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->create();
        $action = app(SeedSubnetAddresses::class);
        $action->execute($subnet);

        IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->where('address', '198.51.100.11')
            ->update(['status' => IpAddressStatus::Assigned->value]);

        $second = $action->execute($subnet);

        $this->assertTrue($second->wasNoOp());
        $this->assertSame(0, $second->inserted);
        $this->assertSame(
            IpAddressStatus::Assigned,
            IpAddress::query()->where('subnet_id', $subnet->id)->where('address', '198.51.100.11')->sole()->status,
        );
    }

    #[Test]
    public function a_gateway_added_after_seeding_is_taken_out_of_circulation(): void
    {
        // The dangerous case: a subnet seeded before its gateway was known has
        // an allocatable gateway row, and handing it out removes the default
        // route for every host on the subnet at once.
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->withoutGateway()->create();
        $action = app(SeedSubnetAddresses::class);
        $action->execute($subnet);

        $this->assertSame(6, IpAddress::query()->where('subnet_id', $subnet->id)->available()->count());

        $subnet->update(['gateway' => '198.51.100.14']);
        $result = $action->execute($subnet);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(5, $result->allocatable);
        $this->assertSame(
            IpAddressStatus::Unavailable,
            IpAddress::query()->where('subnet_id', $subnet->id)->where('address', '198.51.100.14')->sole()->status,
        );
        $this->assertFalse($result->needsOperatorAttention());
    }

    #[Test]
    public function a_gateway_that_is_already_assigned_is_reported_rather_than_silently_overwritten(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->withoutGateway()->create();
        $action = app(SeedSubnetAddresses::class);
        $action->execute($subnet);

        IpAddress::query()
            ->where('subnet_id', $subnet->id)
            ->where('address', '198.51.100.14')
            ->update(['status' => IpAddressStatus::Assigned->value]);

        $subnet->update(['gateway' => '198.51.100.14']);
        $result = $action->execute($subnet);

        // Flipping it to unavailable would hide the real problem: a customer
        // is currently holding the address the router needs.
        $this->assertTrue($result->needsOperatorAttention());
        $this->assertSame(['198.51.100.14'], $result->conflictingAddresses);
    }

    #[Test]
    public function a_point_to_point_slash_31_keeps_both_of_its_addresses(): void
    {
        $subnet = Subnet::factory()->forBlock('192.0.2.0/31')->withoutGateway()->create();

        $result = app(SeedSubnetAddresses::class)->execute($subnet);

        $this->assertSame(2, $result->inserted);
        $this->assertSame(2, $result->allocatable);
        $this->assertSame([], $result->unavailableAddresses);
    }

    #[Test]
    public function an_ipv6_subnet_is_never_expanded_address_by_address(): void
    {
        // A /64 holds 18,446,744,073,709,551,616 addresses. v6 is modelled as
        // a delegated prefix per service instead; nothing in ip_addresses is
        // ever v6.
        $subnet = Subnet::factory()->ipv6('2001:db8:1234::/64')->create();

        $this->expectException(AddressNotAllocatableException::class);

        try {
            app(SeedSubnetAddresses::class)->execute($subnet);
        } finally {
            $this->assertSame(0, IpAddress::query()->where('subnet_id', $subnet->id)->count());
        }
    }

    #[Test]
    public function a_gateway_outside_the_block_is_rejected_before_anything_is_written(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.200')->create();

        $this->expectException(InvalidIpAddressException::class);

        try {
            app(SeedSubnetAddresses::class)->execute($subnet);
        } finally {
            $this->assertSame(0, IpAddress::query()->where('subnet_id', $subnet->id)->count());
        }
    }

    #[Test]
    public function a_larger_subnet_is_written_in_chunks(): void
    {
        // A /22 is 1,024 rows: enough to cross the chunk boundary several
        // times, which is the property that matters — a /16 must never be
        // built as one array or one INSERT.
        $subnet = Subnet::factory()->forBlock('10.40.0.0/22', gateway: '10.40.0.1')->create();

        $result = app(SeedSubnetAddresses::class)->execute($subnet, chunkSize: 100);

        $this->assertSame(1024, $result->inserted);
        $this->assertSame(1024, $result->total);
        $this->assertSame(1021, $result->allocatable);
    }
}
