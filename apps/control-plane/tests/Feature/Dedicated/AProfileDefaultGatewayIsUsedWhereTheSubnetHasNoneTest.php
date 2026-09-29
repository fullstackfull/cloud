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
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
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
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A profile's default gateway fills in for a subnet registered without one (B2).
 *
 * Checkout accepts a Dedicated plan whose profile asks for `{{ ipv4_gateway }}`
 * on a pool with a gateway-less subnet when the profile gives the gateway a
 * default. The build then passed the subnet's null gateway, the renderer let
 * that null replace the default, and the paid order failed
 * `dedicated.install_profile_not_renderable`. Checkout and build now agree:
 * a null the platform passes is no value, and the default applies.
 */
final class AProfileDefaultGatewayIsUsedWhereTheSubnetHasNoneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_paid_order_is_built_with_the_profile_default_gateway(): void
    {
        config()->set('dedicated.provider', 'fake');
        $this->app->singleton(DedicatedProviderFactory::class);
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $datacenter = Datacenter::factory()->create();
        $server = DedicatedServer::factory()->inDatacenter($datacenter)->profile('ded-1')->create();
        BmcEndpoint::factory()->forServer($server)->at('192.0.2.10')->create();
        ServerComponent::factory()->forServer($server)->nic('aa:bb:cc:dd:ee:02')->create();

        $pool = IpPool::factory()->create(['datacenter_id' => $datacenter->getKey()]);
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29')->create([
            'ip_pool_id' => $pool->getKey(),
            'gateway' => null,
        ]);
        app(SeedSubnetAddresses::class)->execute($subnet);

        OsInstallProfile::factory()->create([
            'installer' => InstallerKind::Autoinstall,
            'template' => "h: {{ hostname }}\ngw {{ ipv4_gateway }}\n",
            'defaults' => ['ipv4_gateway' => '198.51.100.14'],
        ]);

        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['kind' => 'dedicated'])->getKey(),
            'resources' => ['hardware_profile' => 'ded-1', 'ipv4_count' => 1],
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 45_000,
            'setup_amount_minor' => 0,
        ]);

        PxeBootAuthorisation::created(static function (PxeBootAuthorisation $authorisation): void {
            $authorisation->forceFill([
                'status' => PxeAuthorisationStatus::Completed,
                'booted_at' => now(),
                'completed_at' => now(),
            ])->saveQuietly();
        });

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine((string) $plan->getKey(), 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: null,
        ));

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        app(SettleInvoice::class)->execute($invoice, Transaction::factory()->create([
            'customer_id' => $customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]));

        $service = Service::query()->where('order_id', $order->getKey())->sole();

        $this->assertSame(ServiceStatus::Active, $service->status, 'Checkout accepted the plan and the build refused it.');
        $this->assertSame(DedicatedServerStatus::Active, $server->refresh()->status);

        $rendered = (string) (PxeBootAuthorisation::query()->sole()->rendered_config['template'] ?? '');
        $this->assertStringContainsString('gw 198.51.100.14', $rendered);
    }
}
