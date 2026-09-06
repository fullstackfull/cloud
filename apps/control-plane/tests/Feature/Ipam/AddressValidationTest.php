<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Addresses are stored as strings because that is what hypervisors, DNS and
 * operators all speak — but a string column will accept anything, and a row
 * containing "10.0.0.256" is only discovered when a build fails hours later.
 * The validation therefore sits at the model boundary, where nothing can go
 * around it.
 */
final class AddressValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_address_that_is_not_an_address_is_refused_at_the_model(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->create();

        $this->expectException(InvalidIpAddressException::class);

        try {
            IpAddress::factory()->for($subnet)->create(['address' => '198.51.100.256']);
        } finally {
            $this->assertSame(0, IpAddress::query()->count());
        }
    }

    #[Test]
    public function an_address_is_stored_in_one_canonical_spelling(): void
    {
        $subnet = Subnet::factory()->ipv6('2001:db8:9::/64')->create();

        // Two spellings of one v6 address must not become two rows behind the
        // (subnet_id, address) unique index.
        $address = IpAddress::factory()->for($subnet)->create(['address' => '2001:0DB8:0009:0000::00A1']);

        $this->assertSame('2001:db8:9::a1', $address->fresh()?->address);
    }

    #[Test]
    public function a_subnet_written_from_one_of_its_hosts_is_stored_as_the_block(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '198.51.100.13/29', 'prefix_length' => 29]);

        $this->assertSame('198.51.100.8/29', $subnet->fresh()?->cidr);
    }

    #[Test]
    public function a_gateway_that_is_not_an_address_is_refused(): void
    {
        $this->expectException(InvalidIpAddressException::class);

        Subnet::factory()->forBlock('198.51.100.8/29')->create(['gateway' => 'the-router']);
    }
}
