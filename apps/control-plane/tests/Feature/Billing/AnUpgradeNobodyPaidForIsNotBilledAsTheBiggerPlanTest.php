<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * An upgrade whose invoice is voided, or not yet paid, does not leave the
 * subscription billed as the plan it has not paid for.
 *
 * A plan change moves the subscription's plan and recurring amount the moment
 * an upgrade is confirmed; the bigger machine waits for the invoice. Measured
 * before this was fixed:
 *
 *  - an operator voided the upgrade's invoice and the subscription stayed on
 *    large, recurring 18.000, and renewed at 18.000 for a small machine;
 *  - after that void, a further upgrade credited the unpaid large plan's
 *    remainder (30.000) and invoiced only the difference to the next plan;
 *  - an upgrade left unpaid across the renewal renewed at the larger amount
 *    while the machine was still small, leaving two open invoices.
 *
 * Fixture: a 30-day period (1 April to 1 May), twenty days in. small 9.000,
 * large 90.000, xl 100.000; remainders are a third.
 */
final class AnUpgradeNobodyPaidForIsNotBilledAsTheBiggerPlanTest extends BillingApiTestCase
{
    private Product $product;

    private Plan $small;

    private Plan $large;

    private Plan $xl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:00:00', 'UTC'));
        $this->freezeTime();

        $this->product = Product::factory()->create(['kind' => 'vps']);
        $this->small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $this->large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $this->xl = $this->plan('xl', ['vcpu' => 16, 'memory_mib' => 32768, 'disk_gib' => 320], 100_000);
    }

    #[Test]
    public function a_voided_upgrade_puts_the_subscription_back_on_the_plan_it_paid_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'void-back-1')->assertOk();
        $this->assertSame(90_000, $subscription->fresh()?->recurring_amount_minor);

        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $fresh = $subscription->fresh();
        $this->assertSame($this->small->id, $fresh?->plan_id);
        $this->assertSame(9_000, $fresh?->recurring_amount_minor);
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count(), 'Nothing was grown, so nothing is shrunk.');
    }

    #[Test]
    public function the_renewal_after_a_voided_upgrade_bills_the_plan_paid_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'void-renew-1')->assertOk();
        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $this->assertSame(9_000, $this->renewalLineAfterThePeriod($subscription)->unit_amount_minor);
    }

    #[Test]
    public function after_a_voided_upgrade_the_next_upgrade_credits_only_the_plan_paid_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'void-next-1')->assertOk();
        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $this->changePlan($user, $subscription, $this->xl, 'void-next-2')->assertOk();
        $invoice = $this->openUpgradeInvoice($subscription);

        $credit = InvoiceItem::query()->where('invoice_id', $invoice->getKey())->where('kind', InvoiceItemKind::Credit->value)->sole();

        // The unused third of small (3.000) - not of the large plan nobody paid for (30.000).
        $this->assertSame(-3_000, $credit->total_minor);
        $this->assertSame(33_333 - 3_000, $invoice->total_minor);
    }

    #[Test]
    public function an_upgrade_still_unpaid_at_the_renewal_renews_at_the_plan_paid_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'unpaid-renew-1')->assertOk();

        $line = $this->renewalLineAfterThePeriod($subscription);

        $this->assertSame(9_000, $line->unit_amount_minor, 'The machine is still small, and small is what was paid for.');
        $this->assertSame($this->small->nameFor(app()->getLocale()), $line->description);
    }

    #[Test]
    public function once_the_upgrade_is_paid_the_renewal_bills_the_bigger_plan(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'paid-renew-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);
        $upgrade->forceFill(['status' => InvoiceStatus::Paid, 'amount_paid_minor' => $upgrade->total_minor, 'paid_at' => now()])->save();

        $this->assertSame(90_000, $this->renewalLineAfterThePeriod($subscription)->unit_amount_minor);
    }

    // ---- helpers ------------------------------------------------------------

    private function renewalLineAfterThePeriod(Subscription $subscription): InvoiceItem
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-01 00:00:01', 'UTC'));
        app(RenewDueSubscriptions::class)->execute();

        return InvoiceItem::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('kind', InvoiceItemKind::Plan->value)
            ->where('period_start', CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'))
            ->sole();
    }

    private function openUpgradeInvoice(Subscription $subscription): Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)
            ->sole();
    }

    private function changePlan(User $user, Subscription $subscription, Plan $plan, string $key): TestResponse
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $plan->id,
                'price_id' => PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->id,
            ]);
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function plan(string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $this->product->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        return $plan;
    }

    private function paidSubscriptionOn(Customer $customer, Plan $plan): Subscription
    {
        $recurring = PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->recurring_amount_minor;

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $recurring,
            ]);

        $invoice = Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'subtotal_minor' => $recurring,
            'total_minor' => $recurring,
            'amount_paid_minor' => $recurring,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => $recurring,
            'total_minor' => $recurring,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);

        return $subscription;
    }

    private function serviceWithMachine(Customer $customer, Subscription $subscription): Service
    {
        $shape = $subscription->plan()->firstOrFail()->resources;

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $shape,
        ]);

        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create([
                'vcpu' => $shape['vcpu'],
                'memory_mib' => $shape['memory_mib'],
                'disk_gib' => $shape['disk_gib'],
            ]);

        return $service;
    }
}
