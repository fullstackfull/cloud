<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Tests\TestCase;

/**
 * Shared scaffolding for the address endpoints.
 *
 * Two customers appear in nearly every test here on purpose. The interesting
 * question about an address API is not whether it can show you your own
 * address, it is whether it can be talked into showing you — or publishing a
 * PTR onto — somebody else's, and whether the answer to a stranger's id can be
 * told apart from the answer to an invented one.
 */
abstract class IpamApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Whether the queue is faked for this test.
     *
     * The endpoints record a PTR and hand the publishing to a worker, so most
     * tests here want to assert what was recorded and what was dispatched
     * rather than to run the provider call inline. The publication test turns
     * this off and drives the job itself.
     */
    protected bool $fakeQueue = true;

    private ?Subnet $publicSubnet = null;

    private ?Subnet $privateSubnet = null;

    private int $addressCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Every DNS provider in these tests is the fake. The real driver here
         * would be Cloudflare, whose token is not present in this environment
         * — and an adapter for it is not in this build at all, which is what
         * ReverseDnsProviderFactory raises about when the driver is anything
         * else.
         */
        config()->set('billing.providers.dns', FakeReverseDnsProvider::NAME);

        /*
         * One factory for the whole test, which is how a worker holds it: the
         * adapter it resolves is memoised on it, and the fake remembers which
         * PTRs it was asked to publish. Without the singleton, a test asserting
         * on the fake would be looking at a different instance from the one the
         * job used.
         */
        $this->app->singleton(ReverseDnsProviderFactory::class);

        if ($this->fakeQueue) {
            Queue::fake();
        }
    }

    protected function fakeDnsProvider(): FakeReverseDnsProvider
    {
        $provider = $this->app->make(ReverseDnsProviderFactory::class)->make();

        // The configuration above guarantees this; asserting it keeps a
        // misconfigured test from silently exercising a different adapter.
        $this->assertInstanceOf(FakeReverseDnsProvider::class, $provider);

        return $provider;
    }

    /**
     * A customer account with an accepted membership, and the user who holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWith(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, $role)];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * A publicly routed block. Reverse DNS is only published for these.
     */
    protected function publicSubnet(): Subnet
    {
        return $this->publicSubnet ??= Subnet::factory()
            ->forBlock('203.0.113.0/24')
            ->create(['ip_pool_id' => IpPool::factory()]);
    }

    /**
     * RFC 1918 space, which has no delegated PTR zone anywhere.
     */
    protected function privateSubnet(): Subnet
    {
        return $this->privateSubnet ??= Subnet::factory()
            ->forBlock('10.20.30.0/24')
            ->create(['ip_pool_id' => IpPool::factory()->private()]);
    }

    /**
     * One address, assigned to a customer right now.
     *
     * The service is a real row because `ip_assignments.service_id` is a
     * foreign key: an address is attached to something the customer bought, and
     * the schema does not allow a fixture to pretend otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assignmentFor(
        Customer $customer,
        array $attributes = [],
        ?Subnet $subnet = null,
        ?string $address = null,
    ): IpAssignment {
        $subnet ??= $this->publicSubnet();

        $ip = IpAddress::factory()->create([
            'subnet_id' => $subnet->getKey(),
            'address' => $address ?? $this->nextAddressIn($subnet),
            'ip_version' => $subnet->ip_version,
            'status' => IpAddressStatus::Assigned,
        ]);

        // The caller's attributes win: `+` keeps the left operand's keys, so a
        // test asking for a released assignment does not silently get a live one.
        return IpAssignment::factory()->create($attributes + [
            'ip_address_id' => $ip->getKey(),
            'customer_id' => $customer->getKey(),
            'service_id' => Service::factory()->create([
                'customer_id' => $customer->getKey(),
            ])->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
        ]);
    }

    /**
     * The next host in the block, so addresses in one test are distinct and
     * actually inside the subnet they are filed under.
     */
    private function nextAddressIn(Subnet $subnet): string
    {
        $this->addressCounter++;

        $network = (int) ip2long($subnet->block()->networkAddress());

        return (string) long2ip($network + 10 + $this->addressCounter);
    }
}
