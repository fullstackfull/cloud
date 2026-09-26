<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
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
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
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
    public function a_downgrade_from_a_plan_whose_invoice_was_refunded_credits_no_more_than_was_collected(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'void-up-1')->assertOk();

        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())
            ->whereHas('items', fn ($q) => $q->where('kind', InvoiceItemKind::Proration->value))
            ->sole();
        $this->paidThenRefunded($upgrade, $customer);

        $body = $this->changePlan($user, $subscription, $this->small, 'void-down-1')->assertOk()->json('data');

        /*
         * The large plan's remainder (30.000) was paid for and then handed
         * back. The period kept 9.000, so 9.000 is the most that can come back
         * as spendable balance, however the arithmetic of the two plans falls
         * out. (A voided upgrade no longer reaches this: the void puts the
         * subscription back on the plan it came from.)
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

    #[Test]
    public function a_downgrade_credit_is_recorded_against_the_invoice_it_draws_on_and_a_card_refund_is_held_to_the_rest(): void
    {
        /*
         * O-2. The credit was one Adjustment against no invoice, so
         * WhatAnInvoiceStillHolds still read 90.000 on the period's invoice
         * and IssueRefund accepted a card refund of all of it: 117.000 back
         * for 90.000 paid.
         */
        [$customer, $user] = $this->accountWithOwner();
        // Smaller at the same disk, so nothing refuses the move but the money.
        $lean = $this->plan('lean', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $subscription = $this->paidSubscriptionOn($customer, $this->large);
        $this->serviceWithMachine($customer, $subscription);

        /** @var Invoice $period */
        $period = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();
        /** @var Transaction $capture */
        $capture = Transaction::query()->where('invoice_id', $period->getKey())->sole();

        $this->changePlan($user, $subscription, $lean, 'drawn-down-1')->assertOk();

        // 30.000 unused on large, less 3.000 for the rest of the period on small.
        $this->assertSame(27_000, $this->walletOf($customer));
        $this->assertSame(27_000, (int) WalletTransaction::query()
            ->where('invoice_id', $period->getKey())
            ->where('kind', WalletTransactionKind::Adjustment->value)
            ->sum('amount_minor'), 'The credit is recorded against the invoice whose money it is.');
        $this->assertSame(63_000, WhatAnInvoiceStillHolds::minor($period->fresh()));

        Event::fake([RefundIssued::class]);

        try {
            app(IssueRefund::class)->execute($capture, Money::ofMinor(90_000, 'KWD'), 'customer asked');
            $this->fail('The downgrade credit was refunded to the card as well.');
        } catch (RefundExceedsCaptureException $e) {
            $this->assertSame(63_000, $e->context()['refundable_minor']);
        }

        app(IssueRefund::class)->execute($capture, Money::ofMinor(63_000, 'KWD'), 'the rest');
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($period->fresh()));
    }

    #[Test]
    public function a_downgrade_after_a_card_refund_not_yet_booked_credits_nothing_it_took_back(): void
    {
        // The reverse order: the card refund first, still in flight, so the
        // invoice's own refunded figure has not moved.
        [$customer, $user] = $this->accountWithOwner();
        // Smaller at the same disk, so nothing refuses the move but the money.
        $lean = $this->plan('lean', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $subscription = $this->paidSubscriptionOn($customer, $this->large);
        $this->serviceWithMachine($customer, $subscription);

        /** @var Invoice $period */
        $period = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        Refund::query()->create([
            'transaction_id' => Transaction::query()->where('invoice_id', $period->getKey())->sole()->getKey(),
            'invoice_id' => $period->getKey(),
            'amount_minor' => 90_000,
            'currency' => 'KWD',
            'status' => RefundStatus::Pending,
            'reason' => 'in flight at the provider',
        ]);
        $this->assertSame(0, $period->fresh()?->amount_refunded_minor);

        $this->changePlan($user, $subscription, $lean, 'refunded-down-1')->assertOk();

        $this->assertSame(0, $this->walletOf($customer), 'The whole period is on its way back to the card.');
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

    // ---- 5. each change's credit is its own ---------------------------------

    #[Test]
    public function two_downgrades_off_the_same_plan_within_one_second_are_each_credited_and_recorded_as_posted(): void
    {
        $shape = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $basic = $this->plan('basic', $shape, 9_000);
        $pro = $this->plan('pro', $shape, 90_000);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $basic);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $pro, 'second-up-1')->assertOk();
        $this->settle($this->openProrationInvoice($subscription), $customer);
        $this->finishEveryProvisioningJob();

        // From here the settlement listener is on the queue, not yet run.
        Event::fake([InvoicePaid::class]);

        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:10:00.100', 'UTC'));
        $first = $this->changePlan($user, $subscription, $basic, 'second-down-1')->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:10:00.300', 'UTC'));
        $this->changePlan($user, $subscription, $pro, 'second-up-2')->assertOk();
        $upgrade = $this->openProrationInvoice($subscription);

        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:10:00.500', 'UTC'));
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'second-wallet-pay-1')
            ->postJson("/api/v1/invoices/{$upgrade->id}/wallet-credit", [])
            ->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $upgrade->fresh()?->status);

        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:10:00.900', 'UTC'));
        $second = $this->changePlan($user, $subscription, $basic, 'second-down-2')->assertOk();

        $credits = WalletTransaction::query()->where('kind', 'adjustment')->orderBy('created_at')->pluck('amount_minor')->all();
        $this->assertCount(2, $credits, 'Two downgrades, two ledger entries: the second must not be replayed as the first.');

        $this->assertSame(
            [-$first->json('data.net.minor_units'), -$second->json('data.net.minor_units')],
            $credits,
            'What each response says was credited is what the ledger posted.',
        );
        $this->assertSame(
            array_sum($credits),
            (int) PlanChange::query()->sum('wallet_credit_minor'),
            'The change record carries what was posted, which is what the next ceiling subtracts.',
        );
        $this->assertSame(
            array_sum($credits) - $upgrade->total_minor,
            $this->walletOf($customer),
        );
    }

    // ---- 6. what earlier changes returned is subtracted --------------------

    #[Test]
    public function credits_across_one_period_never_come_to_more_than_the_period_collected(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        // Paid: 9.000 for the period, 27.000 for the upgrade. 36.000 in.
        $this->changePlan($user, $subscription, $this->large, 'period-up-1')->assertOk();
        $this->settle($this->openProrationInvoice($subscription), $customer);
        $this->finishEveryProvisioningJob();

        // 27.000 back, legitimately: large's 30.000 remainder less small's 3.000.
        $this->changePlan($user, $subscription, $this->small, 'period-down-1')->assertOk();
        $this->assertSame(27_000, $this->walletOf($customer));

        // Up again, and that invoice is paid and then refunded in full.
        $this->changePlan($user, $subscription, $this->large, 'period-up-2')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($subscription), $customer);

        $this->changePlan($user, $subscription, $this->small, 'period-down-2')->assertOk();

        /*
         * 36.000 came in and 27.000 has already gone back, so 9.000 is all the
         * second downgrade may return. Counting only what came in would hand
         * back another 27.000 - 54.000 out of a period that took 36.000.
         */
        $this->assertSame(36_000, $this->walletOf($customer));
    }

    // ---- 7. the period an order bought counts its recurring money ----------

    #[Test]
    public function a_downgrade_in_the_period_an_order_bought_credits_the_full_remainder(): void
    {
        $cheaper = $this->plan('cheaper', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80], 4_500);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $this->mid, quantity: 1, setupPerUnit: 0);

        $this->changePlan($user, $subscription, $cheaper, 'order-down-1')->assertOk();

        // 3.333 unused on mid, less 1.500 on cheaper: the order paid for it.
        $this->assertSame(3_333 - 1_500, $this->walletOf($customer));
    }

    #[Test]
    public function the_setup_fee_an_order_collected_is_never_returned_as_credit(): void
    {
        $flat = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $unit = $this->plan('unit', $flat, 10_000);
        $dear = $this->plan('dear', $flat, 90_000);
        $cheap = $this->plan('cheap', $flat, 1_000);
        [$customer, $user] = $this->accountWithOwner();

        // Two units at 10.000, with 500 of setup on each unit: 21.000 paid, of
        // which 20.000 bought the period and 1.000 bought the setup.
        $subscription = $this->boughtSubscription($customer, $unit, quantity: 2, setupPerUnit: 500);

        $this->changePlan($user, $subscription, $dear, 'setup-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($subscription), $customer);

        $this->changePlan($user, $subscription, $cheap, 'setup-down-1')->assertOk();

        /*
         * The dear plan's money was handed back, so the ceiling bites: what comes
         * back is the period's recurring money, 20.000, and not a fils of the
         * setup fee.
         */
        $this->assertSame(20_000, $this->walletOf($customer));
    }

    #[Test]
    public function once_a_period_is_renewed_the_order_that_bought_an_earlier_one_is_not_counted_again(): void
    {
        $flat = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $unit = $this->plan('unit', $flat, 10_000);
        $dear = $this->plan('dear', $flat, 90_000);
        $cheap = $this->plan('cheap', $flat, 1_000);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $unit, quantity: 1, setupPerUnit: 0);

        // April was the order's; May is renewed and paid at 10.000.
        $this->travelTo(CarbonImmutable::parse('2026-05-21 00:00:00', 'UTC'));
        $subscription->forceFill([
            'current_period_start' => CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'),
            'current_period_end' => CarbonImmutable::parse('2026-05-31 00:00:00', 'UTC'),
        ])->save();
        $renewal = self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'subtotal_minor' => 10_000,
            'total_minor' => 10_000,
            'amount_paid_minor' => 10_000,
        ]));
        InvoiceItem::query()->create([
            'invoice_id' => $renewal->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => 10_000,
            'total_minor' => 10_000,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);

        $this->changePlan($user, $subscription, $dear, 'renewed-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($subscription), $customer);
        $this->changePlan($user, $subscription, $cheap, 'renewed-down-1')->assertOk();

        // May collected 10.000; April's order money bought April, not May.
        $this->assertSame(10_000, $this->walletOf($customer));
    }

    #[Test]
    public function two_subscriptions_bought_on_one_partly_refunded_order_share_what_it_collected(): void
    {
        $bigDiskSmall = $this->plan('bigdisk-small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        [$customer, $user] = $this->accountWithOwner();

        // One order, two 90.000 lines, paid 180.000 - then 150.000 refunded.
        [$first, $second, $invoice] = $this->oneOrderOfTwoLines($customer, $this->large, 90_000, $this->large, 90_000);
        app(RecordInvoiceRefund::class)->execute($invoice->fresh(), Money::ofMinor(150_000, 'KWD'));

        $this->changePlan($user, $first, $bigDiskSmall, 'siblings-down-1')->assertOk();
        $this->changePlan($user, $second, $bigDiskSmall, 'siblings-down-2')->assertOk();

        /*
         * The order kept 30.000. Each subscription alone would compute 27.000
         * back; together they may have 30.000 and no more.
         */
        $this->assertSame(30_000, $this->walletOf($customer));
    }

    #[Test]
    public function a_sibling_credit_its_own_payments_funded_takes_nothing_from_the_order(): void
    {
        $flat = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $unit = $this->plan('unit', $flat, 10_000);
        $dear = $this->plan('dear', $flat, 90_000);
        $cheap = $this->plan('cheap', $flat, 1_000);
        [$customer, $user] = $this->accountWithOwner();
        [$mine, $sibling] = $this->oneOrderOfTwoLines($customer, $unit, 10_000, $unit, 10_000);

        // The sibling pays for an upgrade itself, then steps back down: that
        // credit is its own proration money coming back, not the order's.
        $this->changePlan($user, $sibling, $dear, 'own-up-1')->assertOk();
        $this->settle($this->openProrationInvoice($sibling), $customer);
        $this->finishEveryProvisioningJob();
        $siblingCredit = -$this->changePlan($user, $sibling, $unit, 'own-down-1')->assertOk()->json('data.net.minor_units');

        $this->changePlan($user, $mine, $dear, 'mine-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($mine), $customer);
        $this->changePlan($user, $mine, $cheap, 'mine-down-1')->assertOk();

        // This subscription's 10.000 of the order is still all there.
        $this->assertSame($siblingCredit + 10_000, $this->walletOf($customer));
    }

    #[Test]
    public function an_order_line_counts_what_it_was_sold_for_after_its_discount(): void
    {
        $flat = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $unit = $this->plan('unit', $flat, 10_000);
        $dear = $this->plan('dear', $flat, 90_000);
        $cheap = $this->plan('cheap', $flat, 1_000);
        [$customer, $user] = $this->accountWithOwner();

        // Line one was sold at half price (5.000); line two at list (10.000).
        [$discounted] = $this->oneOrderOfTwoLines($customer, $unit, 5_000, $unit, 10_000);

        $this->changePlan($user, $discounted, $dear, 'discount-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($discounted), $customer);
        $this->changePlan($user, $discounted, $cheap, 'discount-down-1')->assertOk();

        // 5.000 bought this subscription's period; the list price never arrived.
        $this->assertSame(5_000, $this->walletOf($customer));
    }

    #[Test]
    public function only_this_periods_money_and_this_periods_credits_count(): void
    {
        $flat = ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40];
        $unit = $this->plan('unit', $flat, 10_000);
        $half = $this->plan('half', $flat, 5_000);
        $dear = $this->plan('dear', $flat, 90_000);
        $cheap = $this->plan('cheap', $flat, 1_000);
        [$customer, $user] = $this->accountWithOwner();

        // April: paid 10.000, and a downgrade gives some of it back.
        $subscription = $this->paidSubscriptionOn($customer, $unit);
        $this->serviceWithMachine($customer, $subscription);
        $april = -$this->changePlan($user, $subscription, $half, 'window-down-1')->assertOk()->json('data.net.minor_units');
        $this->assertGreaterThan(0, $april);

        // May: renewed at 5.000 and paid.
        $this->travelTo(CarbonImmutable::parse('2026-05-21 00:00:00', 'UTC'));
        $subscription->refresh()->forceFill([
            'current_period_start' => CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'),
            'current_period_end' => CarbonImmutable::parse('2026-05-31 00:00:00', 'UTC'),
        ])->save();
        $this->renewalPaid($subscription, 5_000);

        $this->changePlan($user, $subscription, $dear, 'window-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($subscription), $customer);
        $this->changePlan($user, $subscription, $cheap, 'window-down-2')->assertOk();

        /*
         * May collected 5.000 and has given nothing back, so 5.000 is May's
         * to return. April's 10.000 is not May's money, and April's credit was
         * April's to give.
         */
        $this->assertSame($april + 5_000, $this->walletOf($customer));
    }

    #[Test]
    public function a_proration_invoice_issued_before_the_change_record_existed_still_delivers_its_plan(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'legacy-up-1')->assertOk();
        $invoice = $this->openProrationInvoice($subscription);

        // As an invoice issued before the migration: no change row behind it.
        PlanChange::query()->delete();

        $this->settle($invoice, $customer);

        $jobs = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get();
        $this->assertCount(1, $jobs, 'Paid money for an upgrade must deliver it, record or no record.');
        $this->assertSame($this->large->id, $jobs->sole()->payload['plan_id'] ?? null);
    }

    #[Test]
    public function a_legacy_invoice_builds_what_its_audit_entry_says_it_bought_not_a_later_plan(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        [$subscription, $first, $second] = $this->twoLegacyUpgrades($customer, $user);

        // Only the cheap first invoice is paid.
        $this->settle($first->fresh(), $customer);

        $built = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get()
            ->map(static fn (ProvisioningJob $job): mixed => $job->payload['plan_id'] ?? null)->all();

        $this->assertSame([$this->mid->id], $built, 'The first invoice bought mid; large is the unpaid second one.');
        $this->assertSame(InvoiceStatus::Open, $second->fresh()?->status);
    }

    #[Test]
    public function a_legacy_invoice_paid_after_a_later_one_builds_nothing_over_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        [$subscription, $first, $second] = $this->twoLegacyUpgrades($customer, $user);

        // The later (large) one is paid first; the earlier (mid) one after.
        $this->settle($second->fresh(), $customer);
        $this->finishEveryProvisioningJob();
        $this->settle($first->fresh(), $customer);

        $built = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get()
            ->map(static fn (ProvisioningJob $job): mixed => $job->payload['plan_id'] ?? null)->all();

        $this->assertSame([$this->large->id], $built, 'Mid must not be built on top of the large plan paid for since.');
    }

    #[Test]
    public function a_legacy_invoice_whose_purchase_cannot_be_established_builds_nothing_when_a_later_change_followed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        [$subscription, $first] = $this->twoLegacyUpgrades($customer, $user);
        AuditEntry::query()->delete();

        $this->settle($first->fresh(), $customer);

        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    // ---- 8. a later settled change decides the machine ---------------------

    #[Test]
    public function a_late_settlement_does_not_build_a_plan_a_settled_downgrade_has_since_replaced(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'late-up-1')->assertOk();
        $upgrade = $this->openProrationInvoice($subscription);

        // Paid, with its InvoicePaid still on the queue.
        Event::fake([InvoicePaid::class]);
        $this->settle($upgrade, $customer);

        // Before the worker gets to it, the customer steps down to mid - which
        // owes nothing, so its own resize is queued at once.
        $this->changePlan($user, $subscription, $this->mid, 'late-down-1')->assertOk();

        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid(
            invoiceId: (string) $upgrade->getKey(),
            customerId: (string) $customer->getKey(),
            orderId: null,
            subscriptionId: (string) $subscription->getKey(),
            paidAt: CarbonImmutable::now(),
        ));

        $built = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get()
            ->map(static fn (ProvisioningJob $job): mixed => $job->payload['plan_id'] ?? null)->all();

        $this->assertSame([$this->mid->id], $built, 'The machine is built to mid; the superseded large plan is not built on top of it.');
    }

    // ---- 9. nothing is dispatched for a change that did not happen ---------

    #[Test]
    public function a_change_that_rolls_back_dispatches_no_resize(): void
    {
        $cheaper = $this->plan('cheaper', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80], 4_500);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->mid);
        $this->serviceWithMachine($customer, $subscription);

        // Fails after the resize job row is written, before the commit.
        AuditEntry::creating(static function (): void {
            throw new RuntimeException('audit store unavailable');
        });

        try {
            $this->changePlan($user, $subscription, $cheaper, 'rollback-down-1')->assertStatus(500);
        } finally {
            AuditEntry::flushEventListeners();
        }

        $this->assertSame($this->mid->id, $subscription->fresh()?->plan_id);
        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    // ---- 10. the quote shows the credit that is posted ---------------------

    #[Test]
    public function the_quoted_amount_is_the_amount_executed_when_the_ceiling_bites(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'quote-up-1')->assertOk();
        $this->paidThenRefunded($this->openProrationInvoice($subscription), $customer);

        $quoted = collect((array) $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id')[$this->small->id];

        $executed = $this->changePlan($user, $subscription, $this->small, 'quote-down-1')->assertOk();

        $this->assertSame(-9_000, $executed->json('data.net.minor_units'));
        $this->assertSame($executed->json('data.net.minor_units'), $quoted['amount_due_now']['minor_units']);
    }

    // ---- 11. a count that cannot be derived is refused on both screens -----

    #[Test]
    public function a_subscription_whose_unit_count_cannot_be_derived_is_refused_the_same_way_on_the_quote_and_the_change(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        // A price the catalogue has since moved off: 8.500 is not a whole
        // number of 9.000 units.
        $subscription->forceFill(['recurring_amount_minor' => 8_500])->save();
        $this->serviceWithMachine($customer, $subscription);

        $options = collect((array) $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id');

        $this->assertContains('unit_count_unknown', $options[$this->large->id]['refusals']);

        $this->changePlan($user, $subscription, $this->large, 'grandfathered-up-1')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.plan_change_refused')
            ->assertJsonPath('error.details.refusals', 'unit_count_unknown');
    }

    // ---- helpers ------------------------------------------------------------

    private function renewalPaid(Subscription $subscription, int $minor): void
    {
        $invoice = self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->getKey(),
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'amount_paid_minor' => $minor,
        ]));

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => $minor,
            'total_minor' => $minor,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);
    }

    /**
     * One paid order of two single-unit lines, each fulfilled into its own
     * subscription, service and machine, in the period the order bought.
     *
     * @return array{0: Subscription, 1: Subscription, 2: Invoice}
     */
    private function oneOrderOfTwoLines(Customer $customer, Plan $firstPlan, int $firstTotal, Plan $secondPlan, int $secondTotal): array
    {
        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);
        $subscriptions = [];

        foreach ([[$firstPlan, $firstTotal], [$secondPlan, $secondTotal]] as $i => [$plan, $total]) {
            $unit = $this->priceOf($plan)->recurring_amount_minor;

            /** @var OrderItem $item */
            $item = OrderItem::query()->create([
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'kind' => 'plan',
                'name' => $plan->slug.'-'.$i,
                'billing_period' => BillingPeriod::Monthly,
                'quantity' => 1,
                'unit_recurring_minor' => $unit,
                'unit_setup_minor' => 0,
                'discount_minor' => $unit - $total,
                'total_minor' => $total,
            ]);

            $subscription = Subscription::factory()
                ->startingOn(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'))
                ->create([
                    'customer_id' => $customer->getKey(),
                    'order_id' => $order->getKey(),
                    'plan_id' => $plan->getKey(),
                    'currency' => 'KWD',
                    'billing_period' => BillingPeriod::Monthly,
                    'recurring_amount_minor' => $unit,
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

            $subscriptions[] = $subscription;
        }

        $paid = $firstTotal + $secondTotal;
        $invoice = self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'subtotal_minor' => $paid,
            'total_minor' => $paid,
            'amount_paid_minor' => $paid,
        ]));

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Order',
            'quantity' => 2,
            'unit_amount_minor' => intdiv($paid, 2),
            'total_minor' => $paid,
        ]);

        return [$subscriptions[0], $subscriptions[1], $invoice];
    }

    /**
     * An upgrade invoice paid and then refunded in full: the plan it bought
     * stays on the subscription, and none of its money stays with the platform.
     */
    private function paidThenRefunded(Invoice $invoice, Customer $customer): void
    {
        $this->settle($invoice, $customer);
        $this->finishEveryProvisioningJob();

        app(RecordInvoiceRefund::class)->execute($invoice->fresh(), Money::ofMinor($invoice->total_minor, $invoice->currency));
    }

    /**
     * Two upgrades as the code before the plan-change record made them:
     * small -> mid, then mid -> large while the first invoice was still open,
     * and no record behind either.
     *
     * @return array{0: Subscription, 1: Invoice, 2: Invoice}
     */
    private function twoLegacyUpgrades(Customer $customer, User $user): array
    {
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->mid, 'legacy-mid-1')->assertOk();
        $first = $this->openProrationInvoice($subscription);

        // The old rule let a second change through while the first was open.
        $first->forceFill(['status' => InvoiceStatus::Draft])->save();
        $this->changePlan($user, $subscription, $this->large, 'legacy-large-1')->assertOk();
        $second = $this->openProrationInvoice($subscription);
        $first->forceFill(['status' => InvoiceStatus::Open])->save();

        PlanChange::query()->delete();

        return [$subscription, $first, $second];
    }

    private function openProrationInvoice(Subscription $subscription): Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)
            ->sole();
    }

    /**
     * The resize a settlement queued has finished, so the service is no
     * longer busy - as it would be minutes later on a real worker.
     */
    private function finishEveryProvisioningJob(): void
    {
        ProvisioningJob::query()->update(['status' => ProvisioningJobStatus::Succeeded->value]);
    }

    /**
     * A subscription reached through an order line, paid through the order's
     * invoice, in the period that order bought. Setup is charged per unit
     * here, the reading under which subtracting one setup fee per line would
     * count setup money as recurring.
     */
    private function boughtSubscription(Customer $customer, Plan $plan, int $quantity, int $setupPerUnit): Subscription
    {
        $unit = $this->priceOf($plan)->recurring_amount_minor;
        $total = $unit * $quantity + $setupPerUnit * $quantity;

        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'plan_id' => $plan->getKey(),
            'kind' => 'plan',
            'name' => $plan->slug,
            'billing_period' => BillingPeriod::Monthly,
            'quantity' => $quantity,
            'unit_recurring_minor' => $unit,
            'unit_setup_minor' => $setupPerUnit,
            'total_minor' => $total,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'))
            ->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $unit * $quantity,
            ]);

        $invoice = self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'subtotal_minor' => $total,
            'total_minor' => $total,
            'amount_paid_minor' => $total,
        ]));

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => $plan->slug,
            'quantity' => $quantity,
            'unit_amount_minor' => $unit,
            'total_minor' => $total,
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

        // amount_paid_minor stated: the factory's paid() state reads the
        // definition's total (9.000), not the one given here.
        $invoice = self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'subtotal_minor' => $recurring,
            'total_minor' => $recurring,
            'amount_paid_minor' => $recurring,
        ] + ($refunded ? ['status' => InvoiceStatus::Refunded, 'amount_refunded_minor' => $recurring] : [])));

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

    /**
     * A paid invoice is paid by a capture: every payment applied to an invoice
     * is a transactions row (SettleInvoice's invariant), and what a downgrade
     * credit may draw on is read from those rows (WhatAnInvoiceStillHolds,
     * O-2). A fixture that only states amount_paid_minor describes money that
     * never arrived.
     */
    private static function captured(Invoice $invoice): Invoice
    {
        if ($invoice->amount_paid_minor > 0) {
            Transaction::factory()->create([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $invoice->amount_paid_minor,
                'currency' => $invoice->currency,
            ]);
        }

        return $invoice;
    }
}
