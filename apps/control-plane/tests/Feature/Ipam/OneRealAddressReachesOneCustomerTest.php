<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Database\Seeders\RolePermissionSeeder;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;

/**
 * F-33's sentence, end to end: one real address, two customers.
 *
 * ---------------------------------------------------------------------------
 * Why the harm lives at the definition layer
 * ---------------------------------------------------------------------------
 *
 * Everything downstream of a subnet row is correct. SeedSubnetAddresses writes
 * one row per address per subnet; the allocator locks `ip_addresses` rows with
 * FOR UPDATE SKIP LOCKED; two partial unique indexes keep one live reservation
 * and one live assignment per `ip_address_id`. All of that is keyed on the
 * *row*, and none of it can see that two rows under two overlapping subnets
 * hold the same address *string*. So `203.0.113.0/24` in one pool and
 * `203.0.113.0/25` in another become two rows each holding `203.0.113.10`,
 * every lock and index is satisfied, and two customers are told the same
 * address is theirs.
 *
 * The only place that can refuse it is where the second block is written.
 *
 * ---------------------------------------------------------------------------
 * How this is driven
 * ---------------------------------------------------------------------------
 *
 * The blocks go in through the operator route an operator really uses, into
 * two pools in two buildings that are both labelled private — the label is
 * what the estate believes, and the address is what the world is:
 * `203.0.113.0/24` is not space two buildings can each hold and mean
 * different wires. Whatever the route accepts is then seeded and allocated
 * the way provisioning does it, and the answer is read back from the
 * customer API, which is where two customers would learn they share one.
 */
final class OneRealAddressReachesOneCustomerTest extends IpamApiTestCase
{
    use CreatesIpamFixtures;

    #[Test]
    public function an_overlapping_block_in_another_building_cannot_give_a_second_customer_the_same_address(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $north = $this->privatePoolIn('kw-north');
        $south = $this->privatePoolIn('kw-south');

        $this->actingAs($operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$north->id.'/subnets', [
                'cidr' => '203.0.113.0/24',
                'gateway' => '203.0.113.1',
            ])
            ->assertCreated();

        $second = $this->actingAs($operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$south->id.'/subnets', [
                'cidr' => '203.0.113.0/25',
                'gateway' => '203.0.113.1',
            ]);

        // Whatever the route let in, turn it into addresses and hand them out.
        foreach (Subnet::query()->get() as $subnet) {
            app(SeedSubnetAddresses::class)->execute($subnet);
        }

        [$alice, $aliceUser] = $this->accountWith();
        [$bob, $bobUser] = $this->accountWith();

        $this->takeAnAddressFrom($north, $alice);
        $this->takeAnAddressFrom($south, $bob);

        $aliceHolds = $this->addressesShownTo($aliceUser);
        $bobHolds = $this->addressesShownTo($bobUser);

        $this->assertNotSame([], $aliceHolds, 'The first customer was given nothing; this proves nothing.');

        $shared = array_values(array_intersect($aliceHolds, $bobHolds));

        $this->assertSame(
            [],
            $shared,
            'the same real address reached two customers: '.implode(', ', $shared),
        );

        // And the reason is the refusal at the door, not luck downstream.
        $second->assertStatus(422)
            ->assertJsonPath('error.code', 'infrastructure.subnet_overlaps');

        $this->assertSame(
            ['203.0.113.0/24'],
            Subnet::query()->pluck('cidr')->all(),
            'The refused block was written anyway.',
        );
    }

    private function privatePoolIn(string $site): IpPool
    {
        return IpPool::factory()->private()->create([
            'datacenter_id' => Datacenter::factory()->create(['slug' => $site])->id,
        ]);
    }

    /**
     * Reserve and commit one address from the pool for the customer, the way a
     * provisioning job does. A pool with nothing in it hands out nothing,
     * which is the outcome the refusal is supposed to produce.
     */
    private function takeAnAddressFrom(IpPool $pool, Customer $customer): void
    {
        $allocator = app(IpAllocator::class);

        try {
            $reservation = $allocator->reserve(
                $pool,
                $this->createProvisioningJob(customerId: (string) $customer->getKey()),
                $customer,
            )[0];
        } catch (IpPoolExhaustedException) {
            return;
        }

        $allocator->commit(
            $reservation,
            (string) Service::factory()->create(['customer_id' => $customer->getKey()])->getKey(),
        );
    }

    /**
     * @return list<string>
     */
    private function addressesShownTo(User $user): array
    {
        $rows = (array) $this->actingAs($user)
            ->getJson('/api/v1/ips')
            ->assertOk()
            ->json('data');

        /** @var list<string> $addresses */
        $addresses = array_values(array_column($rows, 'ip_address'));

        return $addresses;
    }
}
