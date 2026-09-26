<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Application\Handlers\ProvisionDedicatedHandler;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Dedicated build that cannot render its answer file leaves nothing held.
 *
 * The verifier's B1: the handler resolved the install profile without asking
 * whether it was still active, reserved a chassis and an address, moved the
 * chassis to `provisioning`, and only then did the renderer refuse the
 * withdrawn profile — Permanent, through the path that holds a machine for
 * review. Nothing had been armed (AuthorisePxeBoot renders before it writes a
 * boot override), yet the chassis was out of stock and the address reserved.
 * The same happened with an active profile asking for `{{ ipv4_gateway }}` on
 * an address from a subnet registered without a gateway.
 *
 * Driven through the handler with a job that names an order, because the
 * release rule is keyed on whether this attempt took the hold.
 */
final class ARefusedInstallStrandsNothingTest extends TestCase
{
    use RefreshDatabase;

    private const string PROFILE = 'ded-standard-1';

    private Datacenter $datacenter;

    private DedicatedServer $server;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dedicated.provider', 'fake');
        $this->app->singleton(DedicatedProviderFactory::class);
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $this->customer = Customer::factory()->create();
        $this->datacenter = Datacenter::factory()->create();

        $this->server = DedicatedServer::factory()->inDatacenter($this->datacenter)->profile(self::PROFILE)->create();
        BmcEndpoint::factory()->forServer($this->server)->at('192.0.2.10')->create();
        ServerComponent::factory()->forServer($this->server)->nic('aa:bb:cc:dd:ee:02')->create();
    }

    #[Test]
    public function a_profile_withdrawn_after_the_job_was_made_is_refused_before_anything_is_held(): void
    {
        $pool = $this->pool(gateway: '198.51.100.9');
        $profile = OsInstallProfile::factory()->create();
        $job = $this->job($pool, $profile);

        $profile->forceFill(['is_active' => false])->save();

        $result = app(ProvisionDedicatedHandler::class)->execute($job);

        $this->assertFalse($result->successful);
        $this->assertSame('dedicated.placement_incomplete', $result->errorCode);
        $this->assertNothingHeld($result);
    }

    #[Test]
    public function an_answer_file_the_address_cannot_fill_releases_the_machine_and_the_address(): void
    {
        // Registered without a gateway, which is allowed; the profile asks for one.
        $pool = $this->pool(gateway: null);
        $job = $this->job($pool, OsInstallProfile::factory()->create());

        $result = app(ProvisionDedicatedHandler::class)->execute($job);

        $this->assertFalse($result->successful);
        $this->assertSame('dedicated.install_profile_not_renderable', $result->errorCode);
        $this->assertNothingHeld($result);
    }

    #[Test]
    public function a_job_naming_no_pool_that_exists_is_refused_before_a_machine_is_reserved(): void
    {
        $job = $this->job(null, OsInstallProfile::factory()->create());

        $result = app(ProvisionDedicatedHandler::class)->execute($job);

        $this->assertSame('dedicated.placement_incomplete', $result->errorCode);
        $this->assertNothingHeld($result);
    }

    private function assertNothingHeld(ProvisioningResult $result): void
    {
        $server = $this->server->refresh();

        $this->assertSame(
            DedicatedServerStatus::Available,
            $server->status,
            'A build refused before anything was armed left its machine out of stock: '.(string) $result->errorMessage,
        );
        $this->assertNull($server->reserved_by_order_id);
        $this->assertSame(0, IpReservation::query()->whereNull('released_at')->count(), 'An address is still reserved.');
        $this->assertSame(0, IpAddress::query()->where('status', IpAddressStatus::Reserved->value)->count());
        $this->assertSame(0, PxeBootAuthorisation::query()->count(), 'A boot was authorised for a build that could not run.');
    }

    private function pool(?string $gateway): IpPool
    {
        $pool = IpPool::factory()->create(['datacenter_id' => $this->datacenter->getKey()]);

        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->create([
            'ip_pool_id' => $pool->getKey(),
            // Explicit, including null: the factory fills in a gateway
            // otherwise.
            'gateway' => $gateway,
        ]);
        app(SeedSubnetAddresses::class)->execute($subnet);

        return $pool;
    }

    private function job(?IpPool $pool, OsInstallProfile $profile): ProvisioningJob
    {
        $order = Order::factory()->create(['customer_id' => $this->customer->getKey()]);
        $service = Service::factory()->create(['customer_id' => $this->customer->getKey(), 'kind' => 'dedicated']);

        return ProvisioningJob::factory()->create([
            'order_id' => $order->getKey(),
            'service_id' => $service->getKey(),
            'customer_id' => $this->customer->getKey(),
            'kind' => ProvisioningJobKind::ProvisionDedicated->value,
            'provider' => 'fake',
            'status' => 'running',
            'payload' => [
                'hardware_profile' => self::PROFILE,
                'datacenter_id' => (string) $this->datacenter->getKey(),
                'ip_pool_id' => $pool === null ? '01JZZZZZZZZZZZZZZZZZZZZZZZ' : (string) $pool->getKey(),
                'os_install_profile_id' => (string) $profile->getKey(),
                'ipv4_count' => 1,
            ],
        ]);
    }
}
