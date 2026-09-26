<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
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
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A plan change moves only money the platform actually collected, delivers
 * only what was paid for, and does both or neither.
 *
 * Round two made a plan change settle its money: an upgrade issues an
 * invoice, a downgrade credits the wallet. Three things were still wrong, and
 * the re-audit measured each of them through the customer's own routes
 * (Band A, F-01):
 *
 *  1. The downgrade credit was priced against a plan move whose upgrade
 *     invoice was still unpaid. Three rounds of small -> large -> small paid
 *     nothing in and left 162.000 KWD of spendable wallet credit.
 *  2. Settling ANY proration invoice resized the machine to the
 *     subscription's CURRENT plan. Paying a 0.667 KWD invoice delivered the
 *     90.000 KWD plan while its 53.333 KWD invoice stayed open.
 *  3. The plan move committed in its own transaction before the invoice was
 *     written, so a failed invoice write left the plan moved, no invoice, and
 *     a retry refused as "same plan" - the original F-01 state.
 *
 * The fixture is a 30-day period (1 April to 1 May) twenty days in, so every
 * remainder is exactly a third: small 9.000 -> 3.000, mid 10.000 -> 3.333,
 * large 90.000 -> 30.000.
 */
final class APlanChangeMovesOnlyMoneyThatWasCollectedTest extends BillingApiTestCase
{
    private Product $product;

    private Plan $small;

    private Plan $mid;

    private Plan $large;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:00:00', 'UTC'));
        $this->freezeTime();

        $this->product = Product::factory()->create(['kind' => 'vps']);
        $this->small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $this->mid = $this->plan('mid', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 10_000);
        $this->large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
    }

    // ---- 1. flapping mints nothing -----------------------------------------

    #[Test]
    public function flapping_between_plans_without_paying_mints_no_wallet_credit(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'flap-up-1')->assertOk();

        $down = $this->changePlan($user, $subscription, $this->small, 'flap-down-1');

        /*
         * The move back is refused while the upgrade is unpaid. The refusal is
         * the one a customer can act on: pay the invoice (or have it voided)
         * and the change is theirs to make.
         */
        $down->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.plan_change_refused')
            ->assertJsonPath('error.details.refusals', 'invoice_outstanding');

        $this->assertSame(0, $this->walletOf($customer), 'Nothing was paid in, so nothing may be credited.');
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->count(), 'One upgrade invoice, still open.');
    }

    #[Test]
    public function the_options_screen_says_why_no_plan_can_be_taken_while_an_invoice_is_open(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->mid, 'options-open-1')->assertOk();

        $options = collect((array) $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id');

        $this->assertContains('invoice_outstanding', $options[$this->small->id]['refusals']);
        $this->assertContains('invoice_outstanding', $options[$this->large->id]['refusals']);
    }

    #[Test]
    public function a_downgrade_from_a_plan_whose_invoice_was_voided_credits_no_more_than_was_collected(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'void-up-1')->assertOk();

        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())
            ->whereHas('items', fn ($q) => $q->where('kind', InvoiceItemKind::Proration->value))
            ->sole();
        app(VoidInvoice::class)->execute($upgrade, 'forgiven by an operator');

        $body = $this->changePlan($user, $subscription, $this->small, 'void-down-1')->assertOk()->json('data');

        /*
         * The large plan's remainder (30.000) was never paid for. The period
         * collected 9.000, so 9.000 is the most that can come back as spendable
         * balance, however the arithmetic of the two plans falls out.
         */
        $this->assertLessThanOrEqual(9_000, $this->walletOf($customer));
        $this->assertSame(
            $this->walletOf($customer),
            -$body['net']['minor_units'],
            'What the response says was credited is what the wallet received.'
        );
    }

    #[Test]
    public function a_refunded_period_is_not_credited_a_second_time_by_a_downgrade(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->mid, refunded: true);
        // Same disk as mid, so the downgrade is not refused as a disk shrink.
        $cheaper = $this->plan('cheaper', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80], 4_500);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $cheaper, 'refunded-down-1')->assertOk();

        $this->assertSame(0, $this->walletOf($customer), 'The period was paid and then refunded: nothing is left to credit.');
    }

    #[Test]
    public function a_downgrade_from_a_paid_period_credits_the_unused_remainder_in_full(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->mid);
        $cheaper = $this->plan('cheaper', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80], 4_500);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $cheaper, 'paid-down-1')->assertOk();

        // 3.333 unused on mid, less 1.500 for the rest of the period on cheaper.
        $this->assertSame(3_333 - 1_500, $this->walletOf($customer), 'The cap must not bite on money that was paid.');
    }

    // ---- 2. the resize delivers what the paid invoice bought ---------------

    #[Test]
    public function settling_a_cheap_invoice_resizes_to_the_plan_that_invoice_bought(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->mid, 'cheap-up-1')->assertOk();
        $cheap = Invoice::query()->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)->sole();

        /*
         * Settled, but its InvoicePaid is still on the payments queue - the
         * listener runs later, on a worker. In that window nothing is open, so
         * the customer may change plan again, and does: onto the dearest one.
         */
        Event::fake([InvoicePaid::class]);
        $this->settle($cheap, $customer);
        Event::assertDispatched(InvoicePaid::class);

        $this->changePlan($user, $subscription, $this->large, 'cheap-up-2')->assertOk();
        $dear = Invoice::query()->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)->sole();

        // Now the worker delivers the cheap invoice's settlement.
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid(
            invoiceId: (string) $cheap->getKey(),
            customerId: (string) $customer->getKey(),
            orderId: null,
            subscriptionId: (string) $subscription->getKey(),
            paidAt: CarbonImmutable::now(),
        ));

        $jobs = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get();
        $this->assertCount(1, $jobs);
        $payload = $jobs->sole()->payload;

        $this->assertSame($this->mid->id, $payload['plan_id'] ?? null, 'The 0.667 invoice bought mid, not large.');
        $this->assertSame(4, $payload['vcpu'] ?? null);
        $this->assertSame(80, $payload['disk_gib'] ?? null);
        $this->assertSame(InvoiceStatus::Open, $dear->fresh()?->status, 'The large plan is still unpaid.');
    }

    // ---- 3. the move and its money are one unit ----------------------------

    #[Test]
    public function a_failed_invoice_write_leaves_the_plan_where_it_was_and_the_change_can_be_retried(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $failed = false;
        Invoice::creating(function () use (&$failed): void {
            if (! $failed) {
                $failed = true;

                throw new RuntimeException('invoice store unavailable');
            }
        });

        try {
            $this->changePlan($user, $subscription, $this->large, 'atomic-1')->assertStatus(500);
        } finally {
            Invoice::flushEventListeners();
        }

        $fresh = $subscription->fresh();
        $this->assertSame($this->small->id, $fresh?->plan_id, 'The plan must not move without its invoice.');
        $this->assertSame(9_000, $fresh?->recurring_amount_minor);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->whereHas('items', fn ($q) => $q->where('kind', InvoiceItemKind::Proration->value))->count());
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::PlanChanged->value)->count());

        // And it is recoverable: the same change goes through on retry.
        $this->changePlan($user, $subscription, $this->large, 'atomic-2')->assertOk();
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->count());
    }

    // ---- 4. the change prices what was quoted -------------------------------

    #[Test]
    public function a_client_cannot_price_the_change_at_a_unit_count_it_was_not_quoted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'units-client-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
                'units' => 3,
            ])
            ->assertStatus(422);

        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $subscription->fresh()?->recurring_amount_minor);
    }

    // ---- helpers ------------------------------------------------------------

    private function changePlan(User $user, Subscription $subscription, Plan $plan, string $key): TestResponse
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $plan->id,
                'price_id' => $this->priceOf($plan)->id,
            ]);
    }

    private function walletOf(Customer $customer): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
    }

    private function settle(Invoice $invoice, Customer $customer): void
    {
        $capture = Transaction::factory()
            ->forCustomer($customer)
            ->create(['amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency]);

        app(SettleInvoice::class)->execute($invoice, $capture);
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

    private function priceOf(Plan $plan): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->getKey())->sole();
    }

    /**
     * A subscription on a plan, with the invoice that paid for its current
     * period - the state every real subscription is in once it is running.
     */
    private function paidSubscriptionOn(Customer $customer, Plan $plan, bool $refunded = false): Subscription
    {
        $recurring = $this->priceOf($plan)->recurring_amount_minor;

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
        ] + ($refunded ? ['status' => InvoiceStatus::Refunded, 'amount_refunded_minor' => $recurring] : []));

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

        $node = ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->getKey(),
        ]);

        VirtualMachine::factory()
            ->onNode($node, 900)
            ->forService($service)
            ->create([
                'vcpu' => $shape['vcpu'],
                'memory_mib' => $shape['memory_mib'],
                'disk_gib' => $shape['disk_gib'],
            ]);

        return $service;
    }
}
