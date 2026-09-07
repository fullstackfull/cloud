<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Tests\TestCase;

/**
 * Shared scaffolding for the dedicated-server endpoints.
 *
 * Two customers appear in nearly every test here on purpose. The interesting
 * question about a hardware API is not whether it can show you your own
 * machine, it is whether it can be talked into showing you — or power cycling,
 * or erasing — somebody else's, and whether the answer to a stranger's id can
 * be told apart from the answer to an invented one.
 */
abstract class DedicatedApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Every BMC in these tests is the fake.
         *
         * config/dedicated.php does not read DEDICATED_PROVIDER — phpunit.xml
         * sets that variable and nothing consumes it — so the switch is thrown
         * here explicitly. Without it the factory builds a real Redfish or
         * IPMI adapter and the suite tries to open connections to 192.0.2.x.
         */
        config()->set('dedicated.provider', 'fake');

        /*
         * One factory for the whole test, which is how a queue worker holds
         * it: every adapter it resolves is memoised on it, and the fake
         * remembers each machine's power state. Without the singleton, a test
         * that powers a machine on through the factory would be talking to a
         * different fake than the endpoint is.
         */
        $this->app->singleton(DedicatedProviderFactory::class);

        /*
         * The reinstall endpoint records a provisioning job and dispatches a
         * worker for it. The queue is faked so the tests assert what was
         * recorded rather than running the engine — there is no handler
         * registered for a dedicated reinstall, and running one here would
         * test the absence of a handler rather than the endpoint.
         */
        Queue::fake();
    }

    /**
     * A customer account with an accepted membership, and the user who holds
     * it.
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
     * A machine delivered to a customer: active, attached to a service, and
     * reachable through one management controller.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function serverFor(Customer $customer, array $attributes = []): DedicatedServer
    {
        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'dedicated',
            'status' => ServiceStatus::Active,
        ]);

        // The caller's attributes win: `+` keeps the left operand's keys, so
        // the overrides go first or a test asking for a machine under
        // maintenance silently gets an active one.
        $server = DedicatedServer::factory()->create($attributes + [
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'status' => DedicatedServerStatus::Active,
            'power_state' => PowerState::On,
        ]);

        $this->endpointFor($server);

        return $server;
    }

    /**
     * The machine's out-of-band controller.
     *
     * The address is where the fake's behaviour is selected from, so a test
     * that needs a controller which stops answering asks for one here rather
     * than stubbing an interface.
     */
    protected function endpointFor(DedicatedServer $server, ?string $address = null): BmcEndpoint
    {
        return BmcEndpoint::factory()->forServer($server)->create([
            'protocol' => BmcProtocol::Redfish,
            'address' => $address ?? '192.0.2.'.fake()->numberBetween(2, 254),
            'credentials_reference' => 'bmc-secret-key-name',
        ]);
    }
}
