<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * The platform must not take money for something it already knows it cannot
 * place.
 *
 * ---------------------------------------------------------------------------
 * The shape of the defect
 * ---------------------------------------------------------------------------
 *
 * A Shared Hosting plan with no HostingPackage behind it could be ordered,
 * invoiced, paid, and started on a renewal clock. The service then sat in
 * PENDING with a `placement_blocked_reason` nobody reads, and the subscription
 * went on billing for it.
 *
 * None of that is a provider failure. `ProvisionOrderedService` decides
 * whether a payload can be constructed purely from rows this platform already
 * holds — a hosting package, a cluster, an IP pool, an OS image — and every
 * one of those answers is available before the money moves.
 *
 * ---------------------------------------------------------------------------
 * Local feasibility, not availability
 * ---------------------------------------------------------------------------
 *
 * The distinction these tests keep is between what Lynomia's own database says
 * and what a provider would say if asked. Nothing here contacts a provider,
 * and nothing here claims a machine can actually be built — only that the
 * configuration needed to ask for one exists. A Dedicated plan resolves its
 * chassis inside its own handler from inventory, so it has no local mapping to
 * check and is not gated here.
 */
final class MoneyDoesNotMoveForSomethingUndeliverableTest extends OrdersApiTestCase
{
    // ---- shared hosting ---------------------------------------------------

    #[Test]
    public function a_hosting_plan_with_no_package_cannot_be_ordered(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->hostingPlan(withPackage: false);

        try {
            $this->place($customer, $plan);
            $this->fail('An order was accepted for a plan the platform already knows it cannot place.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        // And no money document exists to be paid.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    #[Test]
    public function the_same_hosting_plan_with_a_package_is_accepted(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->hostingPlan(withPackage: true);

        $order = $this->place($customer, $plan);

        $this->assertSame(1, Order::query()->count());
        $this->assertNotNull(Invoice::query()->where('order_id', $order->getKey())->first());
    }

    // ---- vps --------------------------------------------------------------

    #[Test]
    public function a_vps_plan_with_no_resolvable_placement_cannot_be_ordered(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->vpsPlan(withPlacement: false);

        $this->expectException(CheckoutRejectedException::class);

        $this->place($customer, $plan);
    }

    #[Test]
    public function a_vps_plan_whose_placement_resolves_is_accepted(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->vpsPlan(withPlacement: true);

        $this->place($customer, $plan);

        $this->assertSame(1, Order::query()->count());
    }

    // ---- dedicated is not gated here --------------------------------------

    #[Test]
    public function a_dedicated_plan_is_not_refused_for_want_of_a_local_mapping(): void
    {
        /*
         * A dedicated server reserves a chassis from inventory inside its own
         * handler; there is no catalogue mapping to resolve up front, so there
         * is nothing here that could truthfully be checked. Gating it on
         * something invented would refuse a product that is perfectly orderable.
         */
        [$customer] = $this->accountWithOwner();
        $plan = $this->planFor(ProductKind::Dedicated);

        $this->place($customer, $plan);

        $this->assertSame(1, Order::query()->count());
    }

    // ---- configuration drifts between order and pay -----------------------

    #[Test]
    public function configuration_removed_before_pay_stops_the_payment(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->hostingPlan(withPackage: true);

        $order = $this->place($customer, $plan);
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        // An operator removes the package between the order and the card form.
        HostingPackage::query()->where('plan_id', $plan->getKey())->delete();

        try {
            app(StartInvoicePayment::class)->execute($invoice);
            $this->fail('Money was collected for an order the platform could no longer deliver.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        // The provider was never asked, so no intent and no hold exists.
        $this->assertSame(
            0,
            Transaction::query()->count(),
            'The refusal must land before the provider is called, not after.',
        );
    }

    // ---- one product's absence is not another's ---------------------------

    #[Test]
    public function removing_one_products_mapping_does_not_block_another_product(): void
    {
        [$customer] = $this->accountWithOwner();

        $hosting = $this->hostingPlan(withPackage: false);
        $vps = $this->vpsPlan(withPlacement: true);

        try {
            $this->place($customer, $hosting);
            $this->fail('The hosting plan should have been refused.');
        } catch (CheckoutRejectedException) {
            // Expected.
        }

        // The VPS is untouched by the hosting product's missing configuration.
        $this->place($customer, $vps);

        $this->assertSame(1, Order::query()->count());
    }

    // ---- a free order is still an order -----------------------------------

    #[Test]
    public function an_order_that_costs_nothing_is_refused_on_the_same_grounds(): void
    {
        /*
         * A zero-total order takes a different path through billing: there is
         * nothing to collect, so it is settled at once rather than waiting for
         * a capture, and it goes straight on to fulfilment and a renewal
         * clock. That makes "no money moved" the wrong test to write for it.
         *
         * The rule this phase is about is not only about money leaving an
         * account; it is that the platform must not commit to something it
         * already knows it cannot deliver. A free plan with nothing behind it
         * would be settled into a service that can never exist and a
         * subscription that bills for it from month two.
         */
        [$controlCustomer] = $this->accountWithOwner();
        $placeable = $this->hostingPlan(withPackage: true, monthlyMinor: 0);

        // The positive control, and the thing that makes the refusal below
        // meaningful: a free order for a placeable plan really is accepted and
        // really is settled without a payment.
        $free = $this->place($controlCustomer, $placeable);

        $this->assertTrue(
            $free->fresh()?->status->isPaid(),
            'A zero-total order must settle without a capture; if it does not, this test is not '
            .'exercising the free-order path at all.',
        );

        [$customer] = $this->accountWithOwner();
        $unplaceable = $this->hostingPlan(withPackage: false, monthlyMinor: 0);

        try {
            $this->place($customer, $unplaceable);
            $this->fail('A free order was settled into a service the platform knows it cannot create.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        // Exactly one order exists — the control's. Nothing was settled, no
        // service was created, and no subscription started billing for it.
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Order::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(0, Service::query()->where('plan_id', $unplaceable->getKey())->count());
        $this->assertSame(0, Subscription::query()->where('plan_id', $unplaceable->getKey())->count());
    }

    // ---- what the refusal is allowed to say -------------------------------

    #[Test]
    public function the_refusal_tells_the_customer_nothing_about_the_estate(): void
    {
        /*
         * `error.details` is whatever the exception's class declares with
         * publishing(), and a context is one declaration away from it. The
         * reason a plan cannot be placed names a cluster, an IP pool or a
         * panel package — the shape of the estate, handed to anybody who can
         * reach the checkout endpoint, for a refusal they cannot act on
         * anyway — so it is not carried at all, and this row reads the body.
         */
        [, $user] = $this->accountWithOwner();
        $vps = $this->vpsPlan(withPlacement: false);
        $hosting = $this->hostingPlan(withPackage: false);

        foreach ([$vps, $hosting] as $index => $plan) {
            $response = $this->actingAs($user)
                ->withHeader('Idempotency-Key', 'undeliverable-'.$index)
                ->postJson('/api/v1/orders', [
                    'items' => [['plan_id' => $plan->id, 'quantity' => 1]],
                    'billing_period' => BillingPeriod::Monthly->value,
                ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'checkout.not_deliverable');

            // The sentence is the catalogue's, and it is actionable: not now,
            // nothing charged.
            $this->assertSame(
                'That product is temporarily unavailable to order. '
                .'Nothing has been charged; please try again later.',
                $response->json('error.message'),
            );

            $body = (string) $response->getContent();

            foreach (['cluster', 'IP pool', 'hosting package', 'panel'] as $internal) {
                $this->assertStringNotContainsString(
                    $internal,
                    $body,
                    'The refusal published a detail about the estate: '.$internal,
                );
            }

            // The plan id is the customer's own basket line coming back, and
            // is the only detail the refusal carries.
            $this->assertSame(['plan_id' => $plan->id], $response->json('error.details'));
        }
    }

    // ---- the pool a customer may actually be given ------------------------

    #[Test]
    public function a_management_pool_neither_places_a_machine_nor_hides_the_one_that_can(): void
    {
        /*
         * Management addresses reach the hypervisor and BMC control planes,
         * and IpAllocator refuses to hand one to a customer service. Counting
         * them here would do the damage twice over: beside a customer pool the
         * estate looks ambiguous and a placeable plan is refused; alone, the
         * placement resolves to something guaranteed to fail at the allocator
         * after the money has moved.
         *
         * This is the estate the reference topology actually builds, which is
         * how the case was found.
         */
        [$customer] = $this->accountWithOwner();

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey()]);

        $management = IpPool::factory()->management()->create(['is_active' => true, 'ip_version' => 4]);

        $plan = $this->planFor(ProductKind::Vps);

        // Alone, it is no pool at all.
        try {
            $this->place($customer, $plan);
            $this->fail('A machine was sold onto a management address pool.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        $this->assertSame(0, Order::query()->count());

        // Beside one customer pool it is not ambiguity either: there is still
        // exactly one pool a customer may be given an address from.
        IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);

        $this->place($customer, $plan->fresh(['prices', 'product']));

        $this->assertSame(1, Order::query()->count());
        $this->assertNotNull($management->fresh(), 'The management pool is still there; it is simply not a candidate.');
    }

    // ---- fixtures ---------------------------------------------------------

    private function place(Customer $customer, Plan $plan): Order
    {
        // A hosting line is bought for a domain, which checkout requires; one
        // per customer so no two accounts ever share a name.
        $domain = $plan->product?->kind === ProductKind::SharedHosting
            ? 'shop-'.strtolower(substr((string) $customer->getKey(), -8)).'.example.test'
            : null;

        return app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1, $domain)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: null,
        ));
    }

    private function hostingPlan(bool $withPackage, int $monthlyMinor = 9_000): Plan
    {
        $plan = $this->planFor(ProductKind::SharedHosting, $monthlyMinor);

        if ($withPackage) {
            HostingPackage::factory()->create(['plan_id' => $plan->getKey()]);
        }

        return $plan->fresh(['prices', 'product']);
    }

    private function vpsPlan(bool $withPlacement): Plan
    {
        $plan = $this->planFor(ProductKind::Vps);

        if ($withPlacement) {
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);
            IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);
            VmTemplate::factory()->create(['cluster_id' => $cluster->getKey()]);
        }

        return $plan->fresh(['prices', 'product']);
    }

    private function planFor(ProductKind $kind, int $monthlyMinor = 9_000): Plan
    {
        $product = Product::factory()->create(['kind' => $kind->value]);
        $plan = Plan::factory()->create(['product_id' => $product->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']);
    }
}
