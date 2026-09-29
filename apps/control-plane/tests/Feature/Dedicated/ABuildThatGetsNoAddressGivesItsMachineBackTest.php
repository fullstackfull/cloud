<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Application\Actions\ReserveDedicatedServer;
use Lynomia\Modules\Dedicated\Application\Handlers\ProvisionDedicatedHandler;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Dedicated build refused an address hands its machine back.
 *
 * The re-audit found it with the handler run by hand: ProvisionDedicatedHandler
 * reserved a chassis, moved it to `provisioning`, was refused an address
 * (`ipam.pool_exhausted`, capacity) — and left the chassis in `provisioning`,
 * where the next build could not have it (`dedicated.no_matching_hardware`).
 * Nothing had been armed or powered: the machine had not been touched, and
 * nothing would ever put it back, because compensation releases addresses and
 * knows nothing of chassis.
 *
 * Asked here through the real order path — checkout, payment, the fulfilment
 * listener, the engine's retries — because whether that path released it was
 * what the re-audit could not establish.
 */
final class ABuildThatGetsNoAddressGivesItsMachineBackTest extends TestCase
{
    use RefreshDatabase;

    private const string PROFILE = 'ded-standard-1';

    private Customer $customer;

    private Datacenter $datacenter;

    private IpPool $pool;

    private Subnet $subnet;

    private DedicatedServer $server;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dedicated.provider', 'fake');
        $this->app->singleton(DedicatedProviderFactory::class);

        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->datacenter = Datacenter::factory()->create();

        $this->server = DedicatedServer::factory()->inDatacenter($this->datacenter)->profile(self::PROFILE)->create();
        BmcEndpoint::factory()->forServer($this->server)->at('192.0.2.10')->create();
        ServerComponent::factory()->forServer($this->server)->nic('aa:bb:cc:dd:ee:02')->create();

        // A pool with a block and no address rows: every reservation from it
        // is refused as exhausted.
        $this->pool = IpPool::factory()->create(['datacenter_id' => $this->datacenter->getKey()]);
        $this->subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create([
            'ip_pool_id' => $this->pool->getKey(),
        ]);

        $profile = OsInstallProfile::factory()->create();

        $product = Product::factory()->create(['kind' => 'dedicated']);
        $this->plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            /*
             * The target named on the plan as well as resolvable from the
             * estate, so the order reaches the handler with a complete payload
             * however placement resolves it.
             */
            'resources' => [
                'hardware_profile' => self::PROFILE,
                'ipv4_count' => 1,
                'datacenter_id' => (string) $this->datacenter->getKey(),
                'ip_pool_id' => (string) $this->pool->getKey(),
                'os_install_profile_id' => (string) $profile->getKey(),
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $this->plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 45_000,
            'setup_amount_minor' => 0,
        ]);
    }

    #[Test]
    public function a_paid_order_refused_an_address_leaves_the_machine_in_stock_rather_than_provisioning(): void
    {
        $this->buyAndPay();

        $job = ProvisioningJob::query()->where('kind', ProvisioningJobKind::ProvisionDedicated->value)->sole();

        $this->assertSame(
            ['ipam.pool_exhausted'],
            ProvisioningAttempt::query()
                ->where('provisioning_job_id', $job->getKey())
                ->pluck('error_code')
                ->unique()
                ->values()
                ->all(),
            'The build failed for some other reason than the one this test is about.',
        );

        $server = $this->server->refresh();

        $this->assertSame(
            DedicatedServerStatus::Available,
            $server->status,
            'A build that never armed or powered the machine left it out of stock.',
        );
        $this->assertNull($server->reserved_by_order_id);
        $this->assertNull($server->customer_id);
        $this->assertNull($server->service_id);
    }

    #[Test]
    public function once_the_pool_has_addresses_the_same_machine_can_be_built(): void
    {
        $this->buyAndPay();

        // An operator fixes the pool.
        app(SeedSubnetAddresses::class)->execute($this->subnet);

        $this->completeTheInstallOnFirstPoll();

        // The next order for the same hardware gets the machine the refused
        // build gave back, rather than dedicated.no_matching_hardware.
        $this->buyAndPay();

        $this->assertSame(DedicatedServerStatus::Active, $this->server->refresh()->status);
    }

    #[Test]
    public function a_machine_the_order_already_held_stays_held(): void
    {
        /*
         * An operator holding a machine for a named customer's order, before
         * the build runs. The refused build did not take that hold, so it is
         * not the build's to release: it stays reserved for the order.
         */
        $order = Order::factory()->create(['customer_id' => $this->customer->getKey()]);

        app(ReserveDedicatedServer::class)->execute(
            hardwareProfile: self::PROFILE,
            datacenterId: (string) $this->datacenter->getKey(),
            orderId: (string) $order->getKey(),
            customerId: (string) $this->customer->getKey(),
            holdMinutes: 0,
        );

        $job = ProvisioningJob::factory()->create([
            'order_id' => $order->getKey(),
            'customer_id' => $this->customer->getKey(),
            'kind' => ProvisioningJobKind::ProvisionDedicated->value,
            'status' => 'running',
            'payload' => $this->plan->resources,
        ]);

        $result = app(ProvisionDedicatedHandler::class)->execute($job);

        $this->assertSame('ipam.pool_exhausted', $result->errorCode);

        $server = $this->server->refresh();
        $this->assertSame(DedicatedServerStatus::Reserved, $server->status);
        $this->assertSame((string) $order->getKey(), (string) $server->reserved_by_order_id);
    }

    private function buyAndPay(): Order
    {
        $order = app(PlaceOrder::class)->execute(
            $this->customer,
            new CheckoutRequest(
                lines: [new CheckoutLine((string) $this->plan->getKey(), 1)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: null,
            ),
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);

        return $order->refresh();
    }

    private function completeTheInstallOnFirstPoll(): void
    {
        PxeBootAuthorisation::created(static function (PxeBootAuthorisation $authorisation): void {
            $authorisation->forceFill([
                'status' => PxeAuthorisationStatus::Completed,
                'booted_at' => now(),
                'completed_at' => now(),
            ])->saveQuietly();
        });
    }
}
