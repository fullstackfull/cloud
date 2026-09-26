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
 * One figure, read by the three actions that hand money back, so that they
 * cannot between them return the same money twice:
 *
 *  - CreditWhatACancelledOrderPaid credits a cancelled order's remainder to the
 *    wallet;
 *  - IssueRefund returns part of a capture to the card (or to the wallet it was
 *    spent from);
 *  - RecordInvoiceRefund books a refund against the document.
 *
 * The figure is:
 *
 *     captured charges applied to the invoice
 *   − stored value already credited to the wallet against it (a top-up carrying
 *     the invoice's id: SettleInvoice's overpayment surplus, a compensation for
 *     a capture that landed on a withdrawn invoice, a cancelled order's credit)
 *   − what has gone back by refund: the larger of the invoice's
 *     `amount_refunded_minor` and the refund rows still holding funds against
 *     its captures (a pending card refund is not on the invoice yet; a refund
 *     an operator booked straight onto the invoice has no row)
 *
 * Read it under the invoice's row lock; each of the three actions holds it.
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
            ->where('kind', WalletTransactionKind::Topup->value)
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
