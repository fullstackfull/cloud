<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
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
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

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

    private Plan $mid;

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
        $this->mid = $this->plan('mid', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 10_000);
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

        $upgrade = $this->openUpgradeInvoice($subscription);

        $line = $this->renewalLineAfterThePeriod($subscription);

        $this->assertSame(9_000, $line->unit_amount_minor, 'The machine is still small, and small is what was paid for.');
        $this->assertSame($this->small->nameFor(app()->getLocale()), $line->description);

        // The upgrade lapsed with the period it was priced for.
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $subscription->fresh()?->recurring_amount_minor);
    }

    #[Test]
    public function a_lapsed_upgrade_cannot_be_paid_after_the_renewal_and_built_for_the_new_period(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'lapse-pay-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);
        $this->renewalLineAfterThePeriod($subscription);

        /*
         * Before: the renewal billed the old amount, the small proration
         * invoice stayed payable, and paying it built a whole month of the
         * bigger plan - every month.
         */
        try {
            app(SettleInvoice::class)->execute(
                $upgrade->fresh(),
                Transaction::factory()->forCustomer($customer)->create(['amount_minor' => $upgrade->total_minor, 'currency' => 'KWD']),
            );
        } catch (Throwable) {
            // Refused: a void invoice cannot be paid. Asserted by its effects.
        }

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function the_void_undoes_the_upgrade_before_it_returns_so_nothing_can_act_in_between(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'sync-up-1')->assertOk();

        // Nothing queued runs from here on.
        Queue::fake();
        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id, 'Undone with the void, not after a queue.');

        // So the customer's next change is from small, and credits nothing.
        $this->changePlan($user, $subscription, $this->mid, 'sync-mid-1')->assertOk();
        $this->assertSame(0, $this->walletOf($customer));
        $this->assertSame(333, $this->openUpgradeInvoice($subscription)->total_minor);
    }

    #[Test]
    public function a_void_that_rolls_back_leaves_the_upgrade_in_place(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'rollback-void-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        try {
            DB::transaction(static function () use ($upgrade): void {
                app(VoidInvoice::class)->execute($upgrade, 'forgiven by an operator');

                throw new RuntimeException('the operator action around the void failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id, 'The restore is part of the void and rolls back with it.');
    }

    #[Test]
    public function the_restore_takes_the_plan_lock_a_checkout_takes(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'restore-lock-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        $locked = [];
        DB::listen(static function (QueryExecuted $query) use (&$locked): void {
            if (str_contains($query->sql, '"plans"') && str_contains($query->sql, 'for update')) {
                $locked[] = $query->bindings;
            }
        });

        app(VoidInvoice::class)->execute($upgrade, 'forgiven by an operator');

        $this->assertContains([$this->small->id], $locked, 'The plan the subscription goes back to is locked as a checkout locks it.');
    }

    #[Test]
    public function an_upgrade_with_money_already_on_its_invoice_does_not_lapse_and_renews_at_the_old_amount(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'partial-up-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);
        // Part-paid: a void would disown money that arrived, so it cannot lapse.
        $upgrade->forceFill(['amount_paid_minor' => 1_000])->save();

        $line = $this->renewalLineAfterThePeriod($subscription);

        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);
        $this->assertSame(9_000, $line->unit_amount_minor, 'Billed at what was paid for until the upgrade is.');
    }

    #[Test]
    public function a_restore_that_fails_fails_the_void_with_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'restore-fails-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        AuditEntry::creating(static function (): void {
            throw new RuntimeException('audit store unavailable');
        });

        try {
            app(VoidInvoice::class)->execute($upgrade, 'forgiven by an operator');
            $this->fail('The void must not succeed without its restore.');
        } catch (RuntimeException) {
        } finally {
            AuditEntry::flushEventListeners();
        }

        // Neither half happened: no void invoice with the upgrade still billed.
        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function an_upgrade_invoice_voided_without_being_undone_still_credits_only_what_was_paid_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'behind-up-1')->assertOk();

        // Voided around the platform's own action (a data fix), so nothing undid it.
        $this->openUpgradeInvoice($subscription)->forceFill(['status' => InvoiceStatus::Void])->save();

        $quoted = collect((array) $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id')[$this->small->id];

        $executed = $this->changePlan($user, $subscription, $this->small, 'behind-down-1')->assertOk();

        // Small's third out, small's third back in: the large plan was never paid for.
        $this->assertSame(0, $this->walletOf($customer));
        $this->assertSame(0, $executed->json('data.net.minor_units'));
        $this->assertSame(0, $quoted['amount_due_now']['minor_units'], 'The options screen prices the same credit.');
    }

    #[Test]
    public function an_ended_subscription_is_not_moved_back_by_the_void_of_its_upgrade(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'ended-up-1')->assertOk();
        $subscription->refresh()->forceFill(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()])->save();

        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'wound up with the subscription');

        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::PlanChanged->value)->where('context->reason', 'proration_invoice_voided')->count());
    }

    #[Test]
    public function a_void_restores_nothing_once_the_subscription_has_left_the_plan_it_billed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'left-up-1')->assertOk();
        // An operator has since put it on mid by hand.
        $subscription->refresh()->forceFill(['plan_id' => $this->mid->id, 'recurring_amount_minor' => 10_000])->save();

        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $this->assertSame($this->mid->id, $subscription->fresh()?->plan_id);
        $this->assertSame(10_000, $subscription->fresh()?->recurring_amount_minor);
    }

    #[Test]
    public function a_void_restores_nothing_when_a_later_change_superseded_the_one_it_billed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'superseded-up-1')->assertOk();
        $first = PlanChange::query()->sole();

        /*
         * A later change onto the same plan, recorded after it. The open
         * invoice refusal keeps the platform from making one; the guard is for
         * the rows it did not make (a data fix, an import).
         */
        $later = $first->replicate();
        $later->forceFill(['proration_invoice_id' => null, 'from_plan_id' => $this->large->id, 'from_recurring_amount_minor' => 90_000, 'changed_at' => now()->addMinute()])->save();

        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');

        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function the_plan_an_unpaid_upgrade_left_keeps_its_unit_until_the_upgrade_is_paid(): void
    {
        $capped = $this->plan('capped', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000, stockLimit: 1);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $capped);

        $this->changePlan($user, $subscription, $this->large, 'capacity-up-1')->assertOk();

        $capacity = app(PlanCapacity::class);
        $this->assertSame(1, $capacity->claimed($capped->id), 'Still held: the upgrade is provisional until it is paid.');

        [$other] = $this->accountWithOwner();
        $this->assertSame(PlanCapacity::OUT_OF_STOCK, $capacity->shortfall($capped->fresh(), 1, $other));

        // So the void can always put it back, and the plan is not oversold.
        app(VoidInvoice::class)->execute($this->openUpgradeInvoice($subscription), 'forgiven by an operator');
        $this->assertSame($capped->id, $subscription->fresh()?->plan_id);
        $this->assertSame(1, $capacity->claimed($capped->id));
    }

    #[Test]
    public function the_plan_a_paid_upgrade_left_is_given_its_unit_back(): void
    {
        $capped = $this->plan('capped', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000, stockLimit: 1);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $capped);

        $this->changePlan($user, $subscription, $this->large, 'capacity-paid-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);
        $upgrade->forceFill(['status' => InvoiceStatus::Paid, 'amount_paid_minor' => $upgrade->total_minor, 'paid_at' => now()])->save();

        $this->assertSame(0, app(PlanCapacity::class)->claimed($capped->id));
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

    private function walletOf(Customer $customer): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
    }

    private function boughtSubscription(Customer $customer, Plan $plan): Subscription
    {
        $recurring = PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->recurring_amount_minor;
        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'plan_id' => $plan->getKey(),
            'kind' => 'plan',
            'name' => $plan->slug,
            'billing_period' => BillingPeriod::Monthly,
            'quantity' => 1,
            'unit_recurring_minor' => $recurring,
            'unit_setup_minor' => 0,
            'total_minor' => $recurring,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'))
            ->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $recurring,
            ]);

        Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'subtotal_minor' => $recurring,
            'total_minor' => $recurring,
            'amount_paid_minor' => $recurring,
        ]);

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'subscription_id' => $subscription->getKey(),
            'kind' => 'vps',
            'resources' => $plan->resources,
        ]);

        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create([
                'vcpu' => $plan->resources['vcpu'],
                'memory_mib' => $plan->resources['memory_mib'],
                'disk_gib' => $plan->resources['disk_gib'],
            ]);

        return $subscription;
    }

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
    private function plan(string $slug, array $resources, int $minor, ?int $stockLimit = null): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $this->product->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
            'stock_limit' => $stockLimit,
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
