<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Queries;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;

/**
 * Whether the subscription an invoice bills has ended.
 *
 * Asked by the paths that take money for an invoice (StartInvoicePayment,
 * PayInvoiceFromWallet) and the one that applies a capture to it
 * (SettleInvoiceOnPaymentCaptured): nothing more is delivered on an ended
 * subscription, so an open invoice of it is not paid (O-1, N-3). Read from the
 * subscriptions table's status, CANCELLED or TERMINATED
 * (SubscriptionStatus::isTerminal()); an invoice with no subscription is never
 * refused here.
 */
final class TheSubscriptionAnInvoiceBills
{
    public static function hasEnded(Invoice $invoice): bool
    {
        if ($invoice->subscription_id === null) {
            return false;
        }

        $status = DB::table('subscriptions')->where('id', $invoice->subscription_id)->value('status');

        return is_string($status) && SubscriptionStatus::tryFrom($status)?->isTerminal() === true;
    }
}
