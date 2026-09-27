<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Domain\Events\InvoiceVoided;
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
use Lynomia\Modules\Payments\Application\Actions\ReturnToTheWalletWhatAFailedRefundLeft;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
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
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ProviderThatWithdrawsTheInvoiceMidRefund;
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
    public function a_customer_idempotency_key_shaped_like_the_lapse_key_cannot_block_the_renewal(): void
    {
        /*
         * OX-1, round four's re-audit: the customer's Idempotency-Key went
         * into the wallet ledger's key space raw, beside the system's own
         * predictable keys (invoice:<id>:lapsed-upgrade:<minor>). A wallet
         * payment of the upgrade under exactly that key made the lapse's
         * credit a "replay" of the customer's debit - refused as a conflict -
         * so every renewal threw, and the service ran on unbilled. The
         * customer's key is namespaced now; the lapse posts under its own.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'ox1-up-1')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        $ledger = app(WalletLedger::class);
        $ledger->credit(wallet: $ledger->walletFor($customer, 'KWD'), amount: Money::ofMinor(1_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'top-up');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', sprintf('invoice:%s:lapsed-upgrade:1000', $upgrade->id))
            ->postJson("/api/v1/invoices/{$upgrade->id}/wallet-credit", [])
            ->assertOk();
        $this->assertSame(1_000, $upgrade->fresh()?->amount_paid_minor);

        $line = $this->renewalLineAfterThePeriod($subscription);

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status, 'The upgrade lapsed.');
        $this->assertSame(1_000, $this->walletOf($customer), 'What it held went back to the wallet.');
        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $line->unit_amount_minor, 'And the renewal billed.');

        // The same key replayed by the customer is still their own replay.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', sprintf('invoice:%s:lapsed-upgrade:1000', $upgrade->id))
            ->postJson("/api/v1/invoices/{$upgrade->id}/wallet-credit", [])
            ->assertOk();
        $this->assertSame(1_000, $this->walletOf($customer));
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
    public function a_refund_the_provider_refuses_while_its_invoice_is_withdrawn_returns_what_the_invoice_holds(): void
    {
        $this->assertTheInvoiceWithdrawnDuringTheRefundReachesTheWallet(throws: false);
    }

    #[Test]
    public function a_refund_the_provider_never_answers_while_its_invoice_is_withdrawn_returns_what_the_invoice_holds(): void
    {
        $this->assertTheInvoiceWithdrawnDuringTheRefundReachesTheWallet(throws: true);
    }

    /**
     * N05/N06: IssueRefund's own failure paths. The upgrade is withdrawn (the
     * lapse's ReturnWhatAnInvoiceStillHolds::andWithdraw) while the refund is
     * out at the provider - its pending row counted as money going back, so
     * the withdrawal credits nothing - and the provider then refuses it, or
     * throws. What the void invoice holds again must reach the wallet.
     */
    private function assertTheInvoiceWithdrawnDuringTheRefundReachesTheWallet(bool $throws): void
    {
        [$customer, $upgrade, $capture] = $this->upgradePartPaidByCard(5_000);

        $provider = new ProviderThatWithdrawsTheInvoiceMidRefund(
            function () use ($upgrade, $customer): void {
                $credited = app(ReturnWhatAnInvoiceStillHolds::class)->andWithdraw(
                    $upgrade->fresh(),
                    'lapsed-upgrade',
                    'Payment returned: the upgrade lapsed unpaid',
                    'The upgrade was not paid for in full before the next period was billed.',
                );
                $this->assertSame(0, $credited, 'Precondition: the withdrawal left the money to the refund in flight.');
                $this->assertSame(0, $this->walletOf($customer));
            },
            $throws,
        );
        $registry = new PaymentProviderRegistry($this->app);
        $registry->swap((string) $capture->provider, $provider);
        $issue = new IssueRefund($registry, app(WalletLedger::class), app(ReturnToTheWalletWhatAFailedRefundLeft::class));

        try {
            $refund = $issue->execute($capture, Money::ofMinor(5_000, 'KWD'), 'customer asked');
            $this->assertFalse($throws, 'The provider was to throw.');
            $this->assertSame(RefundStatus::Failed, $refund->status);
        } catch (RuntimeException $e) {
            $this->assertTrue($throws, 'Unexpected: '.$e->getMessage());
        }

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertSame(5_000, $this->walletOf($customer), 'What the failed refund left on the withdrawn invoice reached the wallet.');
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($upgrade->fresh()));
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
    public function a_deadlock_inside_the_wind_up_fails_the_whole_ending_rather_than_vanishing(): void
    {
        /*
         * The round-five verifier: a 40P01 raised inside the wind-up's
         * per-invoice savepoint was caught and logged like any failure, but
         * Laravel does not roll a nested transaction back to its savepoint on
         * a concurrency error - the transaction is aborted - so the outer
         * "commit" was a rollback and the cancellation answered success while
         * nothing had ended. A concurrency error now fails the whole ending.
         */
        [, $upgrade] = $this->upgradePartPaidByCard(5_000);
        $subscription = $this->subscriptionOf($upgrade);

        Event::listen(InvoiceVoided::class, static function (): void {
            throw new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
        });

        $thrown = null;

        try {
            app(CancelSubscription::class)->execute($subscription, immediately: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        // Laravel raises it out of a nested transaction as a DeadlockException
        // (a real one leaves the PostgreSQL transaction aborted, so the
        // caller's rollback undoes the ending; this stand-in cannot abort the
        // test's transaction, so what is asserted is that it is not swallowed).
        $this->assertNotNull($thrown, 'A deadlock inside the wind-up was swallowed and the cancellation reported success.');
        $this->assertStringContainsString('deadlock detected', $thrown->getMessage());
    }

    #[Test]
    public function a_deadlock_inside_the_return_of_an_undelivered_upgrade_fails_the_whole_ending(): void
    {
        /*
         * The same guard as the withdrawal's, on the other savepoint: the
         * return of a paid upgrade the end prevented. A concurrency error
         * there leaves the transaction aborted, and swallowing it made the
         * ending report success while nothing had ended.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'deadlock-in-return')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        // Paid, its settlement not heard: the end returns it.
        Event::fake([InvoicePaid::class]);
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());

        // The only wallet posting of this ending is the upgrade's return.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "wallet_transactions"')) {
                throw new QueryException('pgsql', $query->sql, [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
            }
        });

        $thrown = null;

        try {
            app(CancelSubscription::class)->execute($subscription->fresh(), immediately: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A deadlock inside the return of an undelivered upgrade was swallowed and the ending reported success.');
        $this->assertStringContainsString('deadlock detected', $thrown->getMessage());
    }

    #[Test]
    public function an_upgrade_settled_before_delivered_at_existed_with_its_resize_queued_is_kept(): void
    {
        /*
         * The fallback for a change settled before `delivered_at` was
         * recorded: its resize job, keyed on the invoice, still says it was
         * delivered, and the end keeps the money.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'pre-column-up')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count(), 'Precondition: the resize was queued.');

        // As a row written before the column existed.
        PlanChange::query()->where('proration_invoice_id', $upgrade->getKey())->update(['delivered_at' => null]);

        app(CancelSubscription::class)->execute($subscription->fresh(), immediately: true);

        $this->assertSame(0, $this->walletOf($customer), 'An upgrade whose resize was queued was returned at the end.');
        $this->assertSame(InvoiceStatus::Paid, $upgrade->fresh()?->status);
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

        // The wind-up returned it, before the settlement is heard.
        $this->assertSame($upgrade->fresh()->total_minor, $this->walletOf($customer), 'The wind-up kept an upgrade paid for and never delivered.');

        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid(
            invoiceId: (string) $upgrade->getKey(),
            customerId: (string) $customer->getKey(),
            orderId: null,
            subscriptionId: (string) $subscription->getKey(),
            paidAt: CarbonImmutable::now(),
        ));

        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());

        /*
         * OA-3 (the coordinator's ruling on round four's re-audit): the
         * upgrade was paid for and never delivered - its resize suppressed
         * because the subscription ended - so its money is returned to the
         * wallet, by the wind-up, recorded against the invoice; the
         * settlement heard afterwards returns nothing more. It used to be
         * kept, "an operator's to refund", with a log line.
         */
        $total = $upgrade->fresh()->total_minor;
        $this->assertGreaterThan(0, $total);
        $this->assertSame($total, $this->walletOf($customer), 'The upgrade paid for and never delivered went back to the wallet.');
        $this->assertSame($total, (int) WalletTransaction::query()->where('invoice_id', $upgrade->getKey())->sum('amount_minor'));
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($upgrade->fresh()));
    }

    #[Test]
    public function an_upgrade_paid_before_an_end_its_wind_up_did_not_see_is_returned_by_the_settlement_heard_after(): void
    {
        /*
         * OA-3, the other order: the end came first and its wind-up did not
         * see the paid upgrade (here the subscription is ended outside it),
         * and the settlement is heard after. The settlement suppresses the
         * resize and returns what the invoice holds to the wallet, once.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'paid-then-ended-2')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        Event::fake([InvoicePaid::class]);
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());

        $subscription->fresh()->forceFill(['status' => SubscriptionStatus::Cancelled])->save();

        $paid = new InvoicePaid(
            invoiceId: (string) $upgrade->getKey(),
            customerId: (string) $customer->getKey(),
            orderId: null,
            subscriptionId: (string) $subscription->getKey(),
            paidAt: CarbonImmutable::now(),
        );

        app(ResizeOnPlanChangeSettlement::class)->handle($paid);
        // Redelivered.
        app(ResizeOnPlanChangeSettlement::class)->handle($paid);

        $total = $upgrade->fresh()->total_minor;
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
        $this->assertSame($total, $this->walletOf($customer), 'Returned, once.');
        $this->assertSame(1, WalletTransaction::query()->where('invoice_id', $upgrade->getKey())->where('kind', WalletTransactionKind::Topup->value)->count());
    }

    #[Test]
    public function an_upgrade_whose_resize_was_queued_before_the_end_is_kept(): void
    {
        // The control: heard before the end, the upgrade was delivered (its
        // resize queued), so the end returns nothing of it.
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'paid-then-ended-3')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());

        app(CancelSubscription::class)->execute($subscription->fresh(), immediately: true);

        $this->assertSame(0, $this->walletOf($customer));
        $this->assertSame(InvoiceStatus::Paid, $upgrade->fresh()?->status);
    }

    #[Test]
    public function an_upgrade_whose_settlement_was_heard_while_live_is_kept_though_it_queued_nothing(): void
    {
        /*
         * The round-five verifier: "delivered" was read off whether a resize
         * job existed. An upgrade whose settlement ran while the subscription
         * was live and queued nothing - here there is no machine to resize -
         * was delivered all the same, and must not be returned at the end.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->changePlan($user, $subscription, $this->large, 'paid-live-no-job')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count(), 'Precondition: nothing was queued.');
        $this->assertNotNull(PlanChange::query()->where('proration_invoice_id', $upgrade->getKey())->sole()->delivered_at);

        app(CancelSubscription::class)->execute($subscription->fresh(), immediately: true);

        $this->assertSame(0, $this->walletOf($customer), 'An upgrade delivered while the subscription was live was returned at its end.');
    }

    #[Test]
    public function an_upgrade_a_later_change_superseded_is_not_returned_at_the_end(): void
    {
        /*
         * Paid, its settlement not heard, and then another change made: that
         * later change decided the machine (and a downgrade's credit is drawn
         * on this invoice), so the upgrade was not left undelivered by the
         * end and is not returned as if it were.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->paidSubscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);
        $this->changePlan($user, $subscription, $this->large, 'superseded-up')->assertOk();
        $upgrade = $this->openUpgradeInvoice($subscription);

        Event::fake([InvoicePaid::class]);
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount($upgrade->amountDue())->create());

        $this->changePlan($user, $subscription->fresh(), $this->mid, 'superseded-down')->assertOk();

        app(CancelSubscription::class)->execute($subscription->fresh(), immediately: true);

        $this->assertSame(
            0,
            WalletTransaction::query()->where('invoice_id', $upgrade->getKey())->where('kind', WalletTransactionKind::Topup->value)->count(),
            'A superseded upgrade was returned as if the end had prevented it.',
        );
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
