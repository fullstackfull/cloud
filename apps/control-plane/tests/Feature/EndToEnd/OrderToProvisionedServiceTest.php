<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * From a purchase to a machine somebody is building.
 *
 * The platform's own end-to-end test was called "purchase to active service"
 * and asserted a subscription: no `services` row was ever created by anything
 * but a factory, and every caller of CreateProvisioningJob was a later
 * operation on a machine that already existed. An order could be placed,
 * invoiced, paid, settled and subscribed, and nothing anywhere asked a provider
 * for the thing the customer bought.
 *
 * This test asserts the end of the chain, in both of the ways an order can
 * reach it: money changed hands, and nothing was ever owed.
 */
final class OrderToProvisionedServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        // One cluster and one pool: the shape of a first deployment, and the
        // only shape in which the platform will place a machine by itself.
        ComputeCluster::factory()->create(['status' => 'active']);
        IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);
    }

    private function vpsPlan(int $monthlyMinor = 9_000): Plan
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80, 'ipv4_count' => 1],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    private function place(Plan $plan, ?string $coupon = null): Order
    {
        return app(PlaceOrder::class)->execute(
            $this->customer,
            new CheckoutRequest(
                lines: [new CheckoutLine($plan->id, 1)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: $coupon,
                idempotencyKey: null,
            ),
        );
    }

    private function payFor(Order $order): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);
    }

    #[Test]
    public function a_paid_order_produces_a_service_and_a_queued_build(): void
    {
        /*
         * Only the provisioning job is faked. Faking the whole queue would also
         * swallow the fulfilment listener, which is itself a job — the chain
         * under test would never run, and the test would report an empty
         * database as a defect in the platform rather than in itself.
         */
        Queue::fake([RunProvisioningJob::class]);

        $plan = $this->vpsPlan();
        $order = $this->place($plan);

        $this->payFor($order);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);

        /** @var Service $service */
        $service = Service::query()->where('order_id', $order->id)->sole();
        $this->assertSame(ServiceStatus::Provisioning, $service->status);
        $this->assertSame('vps', $service->kind);
        // Snapshotted from the line, so a later catalogue edit cannot resize a
        // machine somebody is running.
        $this->assertSame(2, $service->resources['vcpu']);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->where('service_id', $service->id)->sole();
        $this->assertSame(ProvisioningJobKind::CreateVps, $job->kind);
        $this->assertSame($order->id, $job->order_id);

        // And it is on the queue, not merely in a table: a row nobody dequeues
        // is a machine nobody builds.
        Queue::assertPushed(RunProvisioningJob::class);

        $this->assertSame(1, Subscription::query()->where('customer_id', $this->customer->id)->count());
    }

    #[Test]
    public function an_order_that_owes_nothing_reaches_the_same_place(): void
    {
        Queue::fake([RunProvisioningJob::class]);

        $plan = $this->vpsPlan();

        $coupon = Coupon::factory()->create([
            'code' => 'ALLFREE',
            'percentage' => '1.000000',
            'applies_to_renewals' => false,
        ]);

        $order = $this->place($plan, $coupon->code);

        // Nothing is owed, so nothing is billed: no invoice, no transaction.
        $this->assertSame(0, $order->total_minor);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(0, Invoice::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, Transaction::query()->count());

        // And yet the customer gets what they ordered, which is the whole point.
        /** @var Service $service */
        $service = Service::query()->where('order_id', $order->id)->sole();
        $this->assertSame(ServiceStatus::Provisioning, $service->status);
        $this->assertSame(1, ProvisioningJob::query()->where('service_id', $service->id)->count());
        $this->assertSame(1, Subscription::query()->where('customer_id', $this->customer->id)->count());

        Queue::assertPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function settling_the_same_order_twice_builds_one_machine(): void
    {
        Queue::fake([RunProvisioningJob::class]);

        $plan = $this->vpsPlan();
        $order = $this->place($plan);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        $settle = app(SettleInvoice::class);

        // A redelivered webhook, a retried job, an operator re-running it.
        $settle->execute($invoice, $transaction);
        $settle->execute($invoice->refresh(), $transaction->refresh());

        $this->assertSame(1, Service::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, ProvisioningJob::query()->count());
        $this->assertSame(1, Subscription::query()->where('customer_id', $this->customer->id)->count());
    }

    #[Test]
    public function a_machine_the_platform_cannot_place_waits_for_an_operator(): void
    {
        Queue::fake([RunProvisioningJob::class]);

        // A second active cluster, and a plan that names neither: the platform
        // has no basis for choosing, and choosing anyway is how a customer's
        // machine appears in the wrong country.
        ComputeCluster::factory()->create(['status' => 'active']);

        $plan = $this->vpsPlan();
        $order = $this->place($plan);
        $this->payFor($order);

        /** @var Service $service */
        $service = Service::query()->where('order_id', $order->id)->sole();

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertStringContainsString('cluster', (string) $service->resources['placement_blocked_reason']);
        $this->assertSame(0, ProvisioningJob::query()->count());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_plan_that_names_its_cluster_is_placed_there(): void
    {
        Queue::fake([RunProvisioningJob::class]);

        $elsewhere = ComputeCluster::factory()->create(['status' => 'active']);
        $pool = IpPool::query()->where('ip_version', 4)->sole();

        $plan = $this->vpsPlan();
        $plan->forceFill([
            'placement_constraints' => [
                'cluster_id' => $elsewhere->id,
                'ip_pool_id' => $pool->id,
                'storage_class' => 'nvme',
            ],
        ])->save();

        $order = $this->place($plan->fresh(['prices', 'product']));
        $this->payFor($order);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->sole();

        $this->assertSame($elsewhere->id, $job->payload['cluster_id']);
        $this->assertSame($pool->id, $job->payload['ip_pool_id']);
    }
}
