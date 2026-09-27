<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Queries;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * How much of the money captured against an invoice has not yet gone back to
 * the customer, by any route.
 *
 * One figure, read by every action that hands an invoice's money back, so
 * that they cannot between them return the same money twice:
 *
 *  - ReturnWhatAnInvoiceStillHolds credits it to the wallet, for the callers
 *    whose invoice will deliver nothing more: CreditWhatACancelledOrderPaid (a
 *    cancelled order), RenewSubscription (an upgrade that lapsed unpaid) and
 *    WindUpAnEndedSubscription (the open invoices of a subscription that has
 *    ended, which it then voids) and ReturnAnUpgradeTheEndPrevented (a paid
 *    upgrade never delivered because its subscription ended, from the
 *    wind-up or from the settlement heard after the end - OA-3);
 *  - CompensateUncollectableCapture credits a capture that landed on a
 *    withdrawn invoice, no more than the invoice still holds of it;
 *  - ApplyPlanChange credits a downgrade's unused time to the wallet, drawn on
 *    the invoices that paid for it (MoneyCollectedForThePeriod::drawnFrom());
 *  - IssueRefund returns part of a capture to the card (or to the wallet it was
 *    spent from);
 *  - RecordInvoiceRefund books a refund against the document.
 *
 * Each records what it returned against the invoice - a wallet entry carrying
 * the invoice's id, or a refund row against its capture - which is what makes
 * it part of this figure for the next one. The renewal's lapse used to keep
 * arithmetic of its own, from the document (`amount_paid - amount_refunded`),
 * which cannot see a refund until the queue books it, and returned a pending
 * card refund's money a second time (N-1).
 *
 * The figure is:
 *
 *     captured charges applied to the invoice
 *   − stored value already credited to the wallet against it (a top-up carrying
 *     the invoice's id: SettleInvoice's overpayment surplus, a compensation for
 *     a capture that landed on a withdrawn invoice, a cancelled order's, a
 *     lapsed upgrade's or an ended subscription's return; and a credit
 *     adjustment carrying it: a downgrade's credit, drawn on the invoice that
 *     paid for the time it returns - O-2)
 *   − what has gone back by refund: the larger of the invoice's
 *     `amount_refunded_minor` and the refund rows still holding funds against
 *     its captures (a pending card refund is not on the invoice yet; a refund
 *     an operator booked straight onto the invoice has no row)
 *
 * Read it under the invoice's row lock; each of those actions holds it.
 *
 * ---------------------------------------------------------------------------
 * The money-path lock order
 * ---------------------------------------------------------------------------
 *
 * Written down once, here, because every action that reads this figure takes
 * the invoice's lock, and most of them take another row beside it. Two
 * actions taking the same two rows in opposite orders deadlock under load,
 * and PostgreSQL resolves it by killing one of them (N-2: IssueRefund took
 * the invoice before the capture, SettleInvoice the capture before the
 * invoice, and a capture attached to its invoice before settlement put the
 * two in a real cycle; the settlement was the one killed, and its queued
 * retries converged - no money lost, a money path failed for nothing).
 *
 *   1. the payment-side row that already exists - the capture
 *      (`transactions`), or the refund (`refunds`);
 *   2. the invoice (`invoices`), several in ascending id order;
 *   3. the subscription (`subscriptions`);
 *   4. the wallet (`wallets`, taken inside WalletLedger, or early through
 *      WalletLedger::lockWalletFor() by a path that must hold it before 5);
 *   5. plans (`plans`, PlanCapacity::lock(), several in ascending id order).
 *
 * Plans come last, after the wallet (OA-1, round four's re-audit): a renewal
 * lapsing a part-paid upgrade holds the wallet (returning what the upgrade
 * held) when its void restores the plan the subscription goes back to
 * (RestorePlanOnVoidedUpgrade locks it), and a sibling subscription of the
 * same customer downgrading onto that plan used to lock the plan (claiming
 * the unit) and only then the wallet (its credit) - 40P01 4/4. A downgrade
 * that will credit the wallet now locks the wallet before it claims the unit;
 * an upgrade posts nothing to the wallet and takes no wallet lock. Checkout
 * locks plans and never the wallet.
 *
 * Who takes what: SettleInvoice (capture, invoice, wallet for a surplus);
 * IssueRefund (capture, invoice; the wallet only after both are released);
 * RecordInvoiceRefund (refund, invoice); PayInvoiceFromWallet (invoice,
 * wallet, then a charge row it has just created, which nobody else can hold);
 * CreditWhatACancelledOrderPaid and ReturnWhatAnInvoiceStillHolds (invoice,
 * wallet); VoidInvoice (invoice, then the subscription and the plan it goes
 * back to through RestorePlanOnVoidedUpgrade); RenewSubscription (the lapsing
 * invoice, the subscription, the wallet, then the plan the lapse's void
 * restores - and it lapses only an invoice it locked before
 * the subscription: an upgrade that appeared after its unlocked read ends the
 * attempt unrenewed, for the next sweep, since locking it after the
 * subscription deadlocked with an operator's void of it); an ended subscription's wind-up (its invoices,
 * the subscription, the wallet); ApplyPlanChange (the subscription, its
 * orders, then the paid invoices a credit draws on, then - for a downgrade -
 * the wallet, then the plan it moves onto - the one invoice lock taken after
 * a subscription, and safe because nothing holding a paid invoice's lock
 * waits for a subscription or an order).
 *
 * That last claim holds because the renewal and the wind-up, which find an
 * open invoice by an unlocked read and lock it before the subscription, lock
 * it only while it is still open (LockAnInvoiceWhileOpen): locked by id, an
 * invoice paid in between was held paid while they waited for the
 * subscription, and a downgrade holding the subscription waited for it (a
 * deadlock the round-four verifier measured). VoidInvoice locks an invoice
 * and waits for the subscription only on a void, which a paid invoice
 * refuses first. Pinned by MoneyPathsTakeTheirLocksInOneOrderTest, and raced
 * across two processes by ARefundAndASettlementDoNotDeadlockTest and
 * ARenewalAndAPlanChangeDoNotDeadlockTest.
 *
 * What it does not do: claw back. A wallet credit the customer has already
 * spent on another invoice stays spent; what this prevents is the same money
 * also going back to the card afterwards. Money credited to the wallet is
 * returned through the wallet or not at all.
 */
final class WhatAnInvoiceStillHolds
{
    public static function capturedMinor(Invoice $invoice): int
    {
        return (int) self::captures($invoice)->sum('amount_minor');
    }

    public static function creditedToTheWalletMinor(Invoice $invoice): int
    {
        return (int) WalletTransaction::query()
            ->where('invoice_id', $invoice->getKey())
            ->where(static fn ($credit) => $credit
                ->where('kind', WalletTransactionKind::Topup->value)
                ->orWhere(static fn ($adjustment) => $adjustment
                    ->where('kind', WalletTransactionKind::Adjustment->value)
                    ->where('amount_minor', '>', 0)))
            ->sum('amount_minor');
    }

    public static function refundedMinor(Invoice $invoice): int
    {
        $rows = (int) Refund::query()
            ->whereIn('transaction_id', self::captures($invoice)->select('id'))
            ->whereIn('status', Refund::fundReservingStatuses())
            ->sum('amount_minor');

        return max($rows, (int) $invoice->amount_refunded_minor);
    }

    public static function minor(Invoice $invoice): int
    {
        return self::capturedMinor($invoice)
            - self::creditedToTheWalletMinor($invoice)
            - self::refundedMinor($invoice);
    }

    /**
     * @return Builder<Transaction>
     */
    private static function captures(Invoice $invoice): Builder
    {
        return Transaction::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('customer_id', $invoice->customer_id)
            ->where('currency', $invoice->currency)
            ->where('kind', TransactionKind::Charge->value)
            ->where('status', TransactionStatus::Succeeded->value);
    }
}
