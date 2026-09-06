<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Application\Actions\ReserveDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\NoMatchingHardwareException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taking one machine out of stock.
 *
 * Stock is finite and specific: a customer ordering a particular hardware
 * profile cannot be served by "something similar", because on physical
 * hardware that is a different disk layout, a different NIC and a different
 * price.
 */
final class ReserveDedicatedServerTest extends TestCase
{
    use RefreshDatabase;

    private Datacenter $datacenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datacenter = Datacenter::factory()->create();
    }

    #[Test]
    public function it_reserves_an_available_machine_of_the_requested_profile(): void
    {
        $server = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->create();

        $reserved = $this->reserve('ded-epyc-64', orderId: null);

        $this->assertSame($server->id, $reserved->id);
        $this->assertSame(DedicatedServerStatus::Reserved, $reserved->status);
        $this->assertNotNull($reserved->reserved_until);
    }

    #[Test]
    public function a_profile_with_no_free_machine_throws_rather_than_substituting_another(): void
    {
        // A machine that would fit in every way except the one the customer
        // paid for.
        DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-xeon-32')
            ->create();

        try {
            $this->reserve('ded-epyc-64');

            $this->fail('A machine of the wrong hardware profile was handed to the order.');
        } catch (NoMatchingHardwareException $e) {
            $this->assertSame('ded-epyc-64', $e->context()['hardware_profile']);
            // The disposition travels with the failure: the order goes to an
            // operator, not to a refund.
            $this->assertSame('manual_review', $e->context()['disposition']);
            // And the count tells the operator whether this is "order more
            // hardware" or "one profile has run out".
            $this->assertSame(1, $e->context()['available_in_datacenter']);
        }

        // The other machine is untouched: nothing was quietly borrowed.
        $this->assertSame(
            DedicatedServerStatus::Available,
            DedicatedServer::query()->where('hardware_profile', 'ded-xeon-32')->sole()->status,
        );
    }

    #[Test]
    public function a_machine_in_another_datacenter_is_never_substituted(): void
    {
        $elsewhere = Datacenter::factory()->create();

        DedicatedServer::factory()->inDatacenter($elsewhere)->profile('ded-epyc-64')->create();

        // Latency, law and the customer's own network all depend on which
        // building the machine is in, so a match on profile alone is not a
        // match.
        $this->expectException(NoMatchingHardwareException::class);

        $this->reserve('ded-epyc-64');
    }

    #[Test]
    public function a_retired_machine_is_never_reserved(): void
    {
        DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->retired()
            ->create();

        $this->expectException(NoMatchingHardwareException::class);

        $this->reserve('ded-epyc-64');
    }

    #[Test]
    public function a_retired_machine_stays_retired_even_when_it_is_the_only_one_of_its_profile(): void
    {
        $retired = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->retired()
            ->create();

        try {
            $this->reserve('ded-epyc-64');
        } catch (NoMatchingHardwareException) {
            // Expected: there is nothing to sell.
        }

        $retired->refresh();

        // The row survives decommissioning so that "what happened to my old
        // server" and "where did this serial go" have an answer. Selling it
        // again would overwrite exactly that.
        $this->assertSame(DedicatedServerStatus::Retired, $retired->status);
        $this->assertNull($retired->reserved_by_order_id);
        $this->assertNotNull($retired->retired_at);
    }

    #[Test]
    public function machines_in_every_non_available_state_are_left_alone(): void
    {
        foreach ([
            DedicatedServerStatus::Reserved,
            DedicatedServerStatus::Provisioning,
            DedicatedServerStatus::Active,
            DedicatedServerStatus::Maintenance,
            DedicatedServerStatus::Failed,
            DedicatedServerStatus::Retired,
        ] as $status) {
            DedicatedServer::factory()
                ->inDatacenter($this->datacenter)
                ->profile('ded-epyc-64')
                ->status($status)
                ->create();
        }

        $this->expectException(NoMatchingHardwareException::class);

        $this->reserve('ded-epyc-64');
    }

    #[Test]
    public function a_retried_job_finds_the_hold_it_already_placed_instead_of_taking_a_second_machine(): void
    {
        DedicatedServer::factory()->count(2)
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->create();

        $order = Order::factory()->create();

        $first = $this->reserve('ded-epyc-64', orderId: (string) $order->getKey());
        $second = $this->reserve('ded-epyc-64', orderId: (string) $order->getKey());

        /*
         * Without this, the retry reserves a SECOND machine and the first is
         * stranded — held for an order now pointing at a different box, with
         * nothing to release it but a person noticing the stock count is wrong.
         */
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DedicatedServer::query()->where('status', DedicatedServerStatus::Reserved->value)->count());
    }

    #[Test]
    public function a_machine_already_being_installed_for_the_order_counts_as_held(): void
    {
        $order = Order::factory()->create();

        // The order has already got past reservation and is mid-install.
        $server = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->status(DedicatedServerStatus::Provisioning)
            ->create(['reserved_by_order_id' => $order->getKey()]);

        // A second, free machine of the same profile, so that a retry which
        // ignored the existing hold would silently succeed with the wrong box.
        DedicatedServer::factory()->inDatacenter($this->datacenter)->profile('ded-epyc-64')->create();

        $found = $this->reserve('ded-epyc-64', orderId: (string) $order->getKey());

        // A retry that reserved another machine while the first was mid-install
        // would leave an installer running on a box nobody is watching.
        $this->assertSame($server->id, $found->id);
        $this->assertSame(DedicatedServerStatus::Provisioning, $found->status);
    }

    #[Test]
    public function stock_rotates_oldest_first_rather_than_handing_out_the_same_machine_repeatedly(): void
    {
        $older = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->create(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);

        DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile('ded-epyc-64')
            ->create(['created_at' => now(), 'updated_at' => now()]);

        $this->assertSame($older->id, $this->reserve('ded-epyc-64')->id);
    }

    #[Test]
    public function a_zero_length_hold_is_indefinite_rather_than_instantly_expired(): void
    {
        DedicatedServer::factory()->inDatacenter($this->datacenter)->profile('ded-epyc-64')->create();

        // What an operator holding a machine for a named customer wants, and
        // what a reaper must not overrule.
        $reserved = $this->reserve('ded-epyc-64', holdMinutes: 0);

        $this->assertNull($reserved->reserved_until);
        $this->assertTrue($reserved->hasLiveReservation());
    }

    private function reserve(string $profile, ?string $orderId = null, ?int $holdMinutes = null): DedicatedServer
    {
        return app(ReserveDedicatedServer::class)->execute(
            hardwareProfile: $profile,
            datacenterId: (string) $this->datacenter->getKey(),
            orderId: $orderId,
            holdMinutes: $holdMinutes,
        );
    }
}
