<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Ipam\Application\Actions\SetReverseDns;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsUnavailableException;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setting a PTR on an address that is being taken away.
 *
 * The liveness check ran before the transaction opened, and a release could
 * land in the gap between them. It is one HTTP request wide, and what fits
 * through it is the worst thing this action can do: the release stamps the PTR
 * row `removing` on its way out, the write puts it back to `pending` carrying
 * the departing customer's hostname, and that name is then published onto an
 * address somebody else now holds.
 */
final class ReverseDnsRaceTest extends TestCase
{
    use RefreshDatabase;

    private function liveAssignment(): IpAssignment
    {
        $pool = IpPool::factory()->create(['scope' => IpPoolScope::Public]);
        $subnet = Subnet::factory()->create(['ip_pool_id' => $pool->id]);
        $address = IpAddress::factory()->create(['subnet_id' => $subnet->id]);

        return IpAssignment::factory()->create([
            'ip_address_id' => $address->id,
            'released_at' => null,
        ]);
    }

    #[Test]
    public function a_hostname_is_published_on_an_address_the_customer_still_holds(): void
    {
        $assignment = $this->liveAssignment();

        $record = app(SetReverseDns::class)->execute($assignment, 'host.example.com');

        $this->assertSame('host.example.com', $record->hostname);
    }

    #[Test]
    public function an_assignment_released_after_the_check_does_not_get_the_hostname(): void
    {
        $assignment = $this->liveAssignment();

        /*
         * The release happens after the caller's liveness check and before the
         * write — the exact window. Simulated by releasing the row while the
         * in-memory model the action was handed still says it is live, which is
         * precisely the state a concurrent release leaves behind.
         */
        IpAssignment::query()->whereKey($assignment->getKey())->update(['released_at' => now()]);

        $this->assertTrue($assignment->isLive(), 'The stale in-memory model should still look live.');

        try {
            app(SetReverseDns::class)->execute($assignment, 'host.example.com');
            $this->fail('A released assignment accepted a hostname.');
        } catch (ReverseDnsUnavailableException $e) {
            // The code, not the prose: the message is free to be reworded.
            $this->assertSame('ipam.reverse_dns_unavailable', $e->errorCode());
        }

        // And nothing was written: no PTR row carrying the departing
        // customer's name is left for the next holder of the address.
        $this->assertSame(0, ReverseDnsRecord::query()->count());
    }
}
