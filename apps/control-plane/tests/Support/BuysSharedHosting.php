<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * A shared-hosting account the way a customer gets one: an order placed at
 * checkout, its invoice settled, and the account built at the (fake) panel by
 * the real fulfilment chain.
 *
 * Built rather than inserted because what F-19 is about is the joins. A
 * hosting account made by a factory has no order behind it, so nothing about
 * the order's lifecycle can be observed on it, and a service made by a factory
 * has no build history, so nothing can be asked about what was built for it.
 *
 * Shared hosting rather than a VPS because its build finishes inside the
 * request on the sync queue with nothing more than a fake panel, so a fixture
 * per test case is cheap enough to be fresh every time.
 */
trait BuysSharedHosting
{
    private ?HostingNode $sharedHostingNode = null;

    private ?Product $sharedHostingProduct = null;

    protected function sharedHostingNode(): HostingNode
    {
        if ($this->sharedHostingNode === null) {
            /*
             * One provider factory for the whole test. The fake panel keeps its
             * accounts per instance, so without this each step would talk to a
             * different panel with nothing on it.
             */
            $this->app->singleton(HostingProviderFactory::class);

            $this->sharedHostingNode = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);
        }

        return $this->sharedHostingNode;
    }

    protected function sharedHostingPlan(string $slug = 'starter', ?int $stockLimit = null): Plan
    {
        $this->sharedHostingNode();

        $product = $this->sharedHostingProduct ??= Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'hosting-'.$slug.'-'.uniqid(),
            'stock_limit' => $stockLimit,
            'resources' => [
                'disk_quota_mib' => 10_240,
                'bandwidth_quota_mib' => 512_000,
                'max_addon_domains' => 10,
                'max_databases' => 10,
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 1_500,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'slug' => 'pkg-'.$slug.'-'.uniqid(),
            'panel_package_name' => 'lyn_'.$slug,
            'disk_quota_mib' => 10_240,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    /**
     * Placed, but not paid for.
     */
    protected function placeSharedHostingOrder(Customer $customer, Plan $plan): Order
    {
        return app(PlaceOrder::class)->execute(
            $customer,
            new CheckoutRequest(
                lines: [new CheckoutLine((string) $plan->getKey(), 1)],
                billingPeriod: BillingPeriod::Monthly,
            ),
        );
    }

    /**
     * Placed and paid for, which on the sync queue is also built.
     */
    protected function buySharedHosting(Customer $customer, Plan $plan): Order
    {
        $order = $this->placeSharedHostingOrder($customer, $plan);

        $this->settleTheInvoiceOf($order);

        return $order->refresh();
    }

    protected function settleTheInvoiceOf(Order $order): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        app(SettleInvoice::class)->execute($invoice, Transaction::factory()->create([
            'customer_id' => $order->customer_id,
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]));

        return $invoice->refresh();
    }

    protected function sharedHostingPanelHas(string $username): bool
    {
        $node = $this->sharedHostingNode();

        foreach (app(HostingProviderFactory::class)->for($node)->listAccounts($node) as $remote) {
            if ($remote->username === $username) {
                return true;
            }
        }

        return false;
    }
}
