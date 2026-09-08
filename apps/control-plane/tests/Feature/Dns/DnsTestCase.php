<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

abstract class DnsTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * The one provider instance every call in a test will get.
     *
     * The factory is deliberately not a singleton in the container, so a test
     * that merely resolved it would arrange one adapter and the code under
     * test would build another — and the zone the test created would not be
     * the zone the code found. Binding it as a singleton here is what makes
     * "the fake holds this zone" a fact about the request.
     */
    private ?FakeDnsProvider $provider = null;

    protected function provider(): FakeDnsProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $provider = new FakeDnsProvider;
        $this->swapProvider($provider);

        return $this->provider = $provider;
    }

    /**
     * Bind the factory once, and only once.
     *
     * `singleton()` drops whatever the container had already resolved, so
     * calling this a second time would hand the code under test a brand new
     * adapter holding none of the zones the test created — which reads as
     * "the record was never published" and is really "the test threw its own
     * fixture away". Found exactly that way.
     */
    protected function swapProvider(DnsProvider $provider): void
    {
        if (! $this->app->isShared(DnsProviderFactory::class)) {
            $this->app->singleton(DnsProviderFactory::class);
        }

        /** @var DnsProviderFactory $factory */
        $factory = $this->app->make(DnsProviderFactory::class);

        $factory->swap($provider);
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, CustomerRole::Owner)];
    }

    protected function memberOf(
        Customer $customer,
        CustomerRole $role = CustomerRole::Member,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    protected function actingFor(Customer $customer): array
    {
        return ['X-Lynomia-Customer' => (string) $customer->getKey()];
    }
}
