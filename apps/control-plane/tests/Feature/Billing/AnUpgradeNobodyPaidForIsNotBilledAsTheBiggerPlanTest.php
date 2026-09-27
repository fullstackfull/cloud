<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
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
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Application\Actions\PayInvoiceFromWallet;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
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
    public function one_fils_paid_on_the_upgrade_does_not_keep_it_alive_across_the_renewal(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'fils-up-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        // One fils in the wallet, spent on the upgrade: a part payment.
        $ledger = app(WalletLedger::class);
        $ledger->credit(wallet: $ledger->walletFor($customer, 'KWD'), amount: Money::ofMinor(1, 'KWD'), kind: WalletTransactionKind::Topup, description: 'top-up');
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'fils-wallet-pay-1')
            ->postJson("/api/v1/invoices/{$upgrade->id}/wallet-credit", [])
            ->assertOk();
        $this->assertSame(1, $upgrade->fresh()?->amount_paid_minor);

        $line = $this->renewalLineAfterThePeriod($subscription);

        // It lapsed all the same: the fils went back, the invoice is void.
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(1, $this->walletOf($customer));
        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $line->unit_amount_minor);

        // And the rest cannot be paid to get the large plan for the new period.
        try {
            app(SettleInvoice::class)->execute(
                $upgrade->fresh(),
                Transaction::factory()->forCustomer($customer)->create(['amount_minor' => $upgrade->total_minor - 1, 'currency' => 'KWD']),
            );
        } catch (Throwable) {
        }

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    #[Test]
    public function a_lapse_returns_exactly_what_the_invoice_still_holds_and_only_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'held-up-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        // 1.000 paid on it - a capture, as every payment is (SettleInvoice's
        // invariant); the figure a lapse returns is read from the captures.
        app(SettleInvoice::class)->execute(
            $upgrade,
            Transaction::factory()->forCustomer($customer)->create(['amount_minor' => 1_000, 'currency' => 'KWD']),
        );
        $this->assertSame(1_000, $upgrade->fresh()?->amount_paid_minor);

        // 400 of it was already returned to the wallet against this invoice.
        $ledger = app(WalletLedger::class);
        $ledger->credit(
            wallet: $ledger->walletFor($customer, 'KWD'),
            amount: Money::ofMinor(400, 'KWD'),
            kind: WalletTransactionKind::Topup,
            description: 'returned earlier',
            invoiceId: (string) $upgrade->getKey(),
        );

        $this->renewalLineAfterThePeriod($subscription);
        // A second sweep, as a redelivered or re-run renewal would be.
        app(RenewDueSubscriptions::class)->execute();

        $returned = WalletTransaction::query()
            ->where('invoice_id', $upgrade->getKey())
            ->where('kind', WalletTransactionKind::Topup->value)
            ->pluck('amount_minor')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([400, 600], $returned, 'The 600 still held, once; never the 1.000 again.');
        $this->assertSame(1_000, $this->walletOf($customer));
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
    }

    #[Test]
    public function a_lapse_after_a_card_refund_not_yet_booked_returns_nothing_twice(): void
    {
        /*
         * N-1. The lapse used to read the document's own paid-less-refunded,
         * and the card refund is booked onto the invoice only when the queued
         * RecordRefundAgainstTheInvoice runs. In that window the lapse handed
         * the 5.000 to the wallet as well: 10.000 back for 5.000 paid, and the
         * refund's booking then failed into failed_jobs.
         */
        Event::fake([RefundIssued::class]);

        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_000);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_000, 'KWD'), 'customer asked');
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(0, $upgrade->fresh()?->amount_refunded_minor, 'Precondition: the refund is not booked yet.');

        $this->renewalLineAfterThePeriod($this->subscriptionOf($upgrade));
        app(RenewDueSubscriptions::class)->execute();

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(0, $this->walletOf($customer), 'The refund returned the 5.000; the lapse returned it again.');

        // The booking the queue would have made now goes through, instead of
        // failing into failed_jobs over money the wallet already had.
        app(RecordInvoiceRefund::class)->execute($upgrade->fresh(), Money::ofMinor(5_000, 'KWD'), $refund->fresh());
        $this->assertSame(5_000, $upgrade->fresh()?->amount_refunded_minor);
        $this->assertSame(0, $this->walletOf($customer));
    }

    #[Test]
    public function a_lapse_leaves_alone_what_a_pending_card_refund_is_already_returning(): void
    {
        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_000);

        // Accepted by the provider and not yet settled there: the row holds
        // the funds, and nothing is on the invoice.
        Refund::query()->create([
            'transaction_id' => $capture->getKey(),
            'invoice_id' => $upgrade->getKey(),
            'amount_minor' => 5_000,
            'currency' => 'KWD',
            'status' => RefundStatus::Pending,
            'reason' => 'in flight at the provider',
        ]);

        $this->renewalLineAfterThePeriod($this->subscriptionOf($upgrade));

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(0, $this->walletOf($customer));
    }

    #[Test]
    public function a_pending_refund_that_fails_after_the_lapse_returns_what_it_had_left_to_the_wallet_once(): void
    {
        /*
         * B2 (the round-four verifier). The lapse leaves to a pending refund
         * what that refund is returning. When the refund then failed, its
         * reservation was released and the void invoice held 5.005 again,
         * credited nowhere.
         */
        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_005);

        // The controlled provider answers pending for an amount ending in 05.
        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');
        $this->assertSame(RefundStatus::Pending, $refund->status);

        $this->renewalLineAfterThePeriod($this->subscriptionOf($upgrade));
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(0, $this->walletOf($customer), 'Precondition: the lapse left it to the refund.');

        $failed = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Failed);
        app(IngestWebhookEvent::class)->execute('fake', $failed->rawPayload, $failed->headers);

        $this->assertSame(RefundStatus::Failed, $refund->fresh()?->status);
        $this->assertSame(5_005, $this->walletOf($customer), 'What the failed refund left on the withdrawn invoice reached the wallet.');
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($upgrade->fresh()));

        // Once: a redelivered failure credits nothing more, and the capture
        // is not refundable to the card as well.
        app(IngestWebhookEvent::class)->execute('fake', $failed->rawPayload, $failed->headers);
        $this->assertSame(5_005, $this->walletOf($customer));

        try {
            app(IssueRefund::class)->execute($capture->fresh(), Money::ofMinor(5_005, 'KWD'), 'again');
            $this->fail('Money returned to the wallet was refunded to the card as well.');
        } catch (RefundExceedsCaptureException) {
        }
    }

    #[Test]
    public function a_pending_refund_that_succeeds_after_the_lapse_is_booked_and_credits_nothing(): void
    {
        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_005);
        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');

        $this->renewalLineAfterThePeriod($this->subscriptionOf($upgrade));

        $done = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);
        app(IngestWebhookEvent::class)->execute('fake', $done->rawPayload, $done->headers);

        $this->assertSame(5_005, $upgrade->fresh()?->amount_refunded_minor);
        $this->assertSame(0, $this->walletOf($customer));
    }

    #[Test]
    public function a_lapse_after_a_wallet_part_payment_was_refunded_to_the_wallet_returns_nothing_twice(): void
    {
        /*
         * The skeptic's production path: the upgrade part-paid from the
         * wallet (POST /invoices/{id}/wallet-credit), that wallet charge
         * refunded - its credit is kind refund, not top-up, so the old lapse
         * did not see it either - and the renewal inside the window before the
         * queue books the refund on the invoice.
         */
        Event::fake([RefundIssued::class]);

        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'wallet-refund-up-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        $ledger = app(WalletLedger::class);
        $ledger->credit(wallet: $ledger->walletFor($customer, 'KWD'), amount: Money::ofMinor(5_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'top-up');
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'wallet-refund-pay-1')
            ->postJson("/api/v1/invoices/{$upgrade->id}/wallet-credit", [])
            ->assertOk();
        $this->assertSame(5_000, $upgrade->fresh()?->amount_paid_minor);
        $this->assertSame(0, $this->walletOf($customer));

        /** @var Transaction $charge */
        $charge = Transaction::query()->where('invoice_id', $upgrade->getKey())->where('provider', 'wallet')->sole();
        app(IssueRefund::class)->execute($charge, Money::ofMinor(5_000, 'KWD'), 'customer asked');
        $this->assertSame(5_000, $this->walletOf($customer));

        $this->renewalLineAfterThePeriod($subscription);

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(5_000, $this->walletOf($customer), '5.000 paid, 5.000 back - not 10.000.');
    }

    #[Test]
    public function an_upgrade_whose_invoice_was_never_paid_and_is_not_open_renews_at_the_old_amount(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'uncollectible-up-1')->assertOk();
        // Written off as uncollectible: not open, so nothing lapses it.
        $this->openUpgradeInvoice($subscription)->forceFill(['status' => InvoiceStatus::Uncollectible])->save();

        $this->assertSame(9_000, $this->renewalLineAfterThePeriod($subscription)->unit_amount_minor);
    }

    #[Test]
    public function a_renewal_locks_the_lapsing_invoice_before_the_subscription(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'lock-order-up-1')->assertOk();

        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            if (! str_contains($query->sql, 'for update')) {
                return;
            }

            foreach (['invoices', 'subscriptions'] as $table) {
                if (str_contains($query->sql, 'from "'.$table.'"')) {
                    $locks[] = $table;
                }
            }
        });

        $this->renewalLineAfterThePeriod($subscription);

        /*
         * VoidInvoice locks the invoice and then (through the restore) the
         * subscription. The renewal takes the two in the same order, so a
         * renewal and an operator's void of the same invoice cannot deadlock.
         */
        $this->assertNotSame([], $locks);
        $this->assertSame('invoices', $locks[0], 'The first row the renewal locks is the lapsing invoice: '.implode(', ', $locks));
        $this->assertContains('subscriptions', $locks);
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

    // ---- an ended subscription's open invoices (O-1) -------------------------

    #[Test]
    public function an_immediate_cancellation_withdraws_its_open_upgrade_and_renewal(): void
    {
        /*
         * O-1. Cancelling now voided nothing: the upgrade's proration and an
         * open renewal stayed payable by card and by wallet, and paying the
         * upgrade queued a resize onto the cancelled subscription's machine.
         */
        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_000);
        $subscription = $this->subscriptionOf($upgrade);

        $renewal = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => 90_000,
            'total_minor' => 90_000,
        ]);

        app(CancelSubscription::class)->execute($subscription, immediately: true);

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()?->status);
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(InvoiceStatus::Void, $renewal->fresh()?->status);
        $this->assertSame(5_000, $this->walletOf($customer), 'What the upgrade held went back to the wallet.');
        $this->assertSame(5_000, (int) WalletTransaction::query()->where('invoice_id', $upgrade->getKey())->sum('amount_minor'));

        // O-4: an ended subscription is not moved back to the plan the voided
        // upgrade left, nor audited as a plan change.
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::PlanChanged->value)->where('context->reason', 'proration_invoice_voided')->count());

        // Neither can be paid any more...
        foreach ([$upgrade, $renewal] as $invoice) {
            try {
                app(PayInvoiceFromWallet::class)->execute($customer, $invoice->fresh(), 'after-cancel-'.$invoice->id);
                $this->fail('An invoice of a cancelled subscription was paid from the wallet.');
            } catch (InvoiceNotPayableException) {
            }
        }

        // ...and a card payment already in flight for the upgrade lands in
        // the wallet, once, rather than on the invoice.
        $late = Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(1_000, 'KWD'))->create(['invoice_id' => $upgrade->getKey()]);
        app(SettleInvoiceOnPaymentCaptured::class)->handle(new PaymentCaptured(
            transactionId: (string) $late->getKey(),
            customerId: (string) $customer->getKey(),
            invoiceId: (string) $upgrade->getKey(),
            provider: (string) $late->provider,
            providerReference: (string) $late->provider_reference,
            amount: Money::ofMinor(1_000, 'KWD'),
            capturedAt: CarbonImmutable::now(),
        ));

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(6_000, $this->walletOf($customer));
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());

        // A card refund of the first capture is now refused: its money is in
        // the wallet.
        try {
            app(IssueRefund::class)->execute($capture->fresh(), Money::ofMinor(5_000, 'KWD'), 'customer asked');
            $this->fail('Money returned to the wallet was refunded to the card as well.');
        } catch (RefundExceedsCaptureException) {
        }
    }

    #[Test]
    public function a_capture_recorded_but_not_yet_settled_when_the_subscription_ends_is_returned_once(): void
    {
        /*
         * RecordPaymentCapture attaches a capture to its invoice before the
         * queue settles it. The wind-up returns what the invoice holds -
         * that capture included - and voids it; the settlement then finds a
         * void invoice and compensates. Crediting the capture in full there
         * as well would hand the same 1.000 back twice.
         */
        [$customer, $upgrade] = $this->upgradePartPaidByCard(5_000);
        $subscription = $this->subscriptionOf($upgrade);

        $inFlight = Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(1_000, 'KWD'))->create(['invoice_id' => $upgrade->getKey()]);

        app(CancelSubscription::class)->execute($subscription, immediately: true);

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(6_000, $this->walletOf($customer));

        app(SettleInvoiceOnPaymentCaptured::class)->handle(new PaymentCaptured(
            transactionId: (string) $inFlight->getKey(),
            customerId: (string) $customer->getKey(),
            invoiceId: (string) $upgrade->getKey(),
            provider: (string) $inFlight->provider,
            providerReference: (string) $inFlight->provider_reference,
            amount: Money::ofMinor(1_000, 'KWD'),
            capturedAt: CarbonImmutable::now(),
        ));

        $this->assertSame(6_000, $this->walletOf($customer), '6.000 paid, 6.000 back - not 7.000.');
    }

    #[Test]
    public function an_upgrade_paid_the_moment_before_the_end_resizes_nothing_after_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'paid-then-ended-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        // Paid, and the settlement's listeners not yet heard.
        Event::fake([InvoicePaid::class]);
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());
        $this->assertSame(InvoiceStatus::Paid, $upgrade->fresh()?->status);

        app(CancelSubscription::class)->execute($subscription, immediately: true);

        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid(
            invoiceId: (string) $upgrade->getKey(),
            customerId: (string) $customer->getKey(),
            orderId: null,
            subscriptionId: (string) $subscription->getKey(),
            paidAt: CarbonImmutable::now(),
        ));

        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    #[Test]
    public function a_cancellation_at_the_end_of_the_period_withdraws_nothing_until_the_period_ends(): void
    {
        [$customer, $upgrade] = $this->upgradePartPaidByCard(5_000);
        $subscription = $this->subscriptionOf($upgrade);

        app(CancelSubscription::class)->execute($subscription);

        // Still running, so the upgrade is still for something.
        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);
        $this->assertSame(0, $this->walletOf($customer));

        app(CancelSubscription::class)->applyScheduled($subscription->fresh(), CarbonImmutable::parse('2026-05-01 00:00:01', 'UTC'));

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()?->status);
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(5_000, $this->walletOf($customer));
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * An upgrade whose invoice took part of its money by card.
     *
     * @return array{Customer, Invoice, Transaction}
     */
    private function upgradePartPaidByCard(int $minor): array
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'card-part-up-'.$minor)->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        $capture = Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor($minor, 'KWD'))->create();
        app(SettleInvoice::class)->execute($upgrade, $capture);

        $this->assertSame($minor, $upgrade->fresh()?->amount_paid_minor);
        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);

        return [$customer, $upgrade, $capture->fresh()];
    }

    private function subscriptionOf(Invoice $invoice): Subscription
    {
        return Subscription::query()->findOrFail($invoice->subscription_id);
    }

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
