<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Queries\LockAnInvoiceWhileOpen;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Subscriptions\Application\Listeners\EndTheSubscriptionWithItsService;
use Lynomia\Modules\Subscriptions\Application\Listeners\RestorePlanOnVoidedUpgrade;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Throwable;

/**
 * Ends a subscription and withdraws what it still asks the customer for.
 *
 * ---------------------------------------------------------------------------
 * Why an ended subscription's open invoices are not left payable
 * ---------------------------------------------------------------------------
 *
 * Nothing more is delivered on a subscription that has ended: no renewal
 * revives it, and no plan change is resized onto it. An invoice of it left
 * open - an upgrade's proration, a renewal - could still be paid, by card or
 * from the wallet, and the money bought nothing (O-1 for an immediate
 * cancellation; N-3 for a service ended from the other side with a partly
 * paid invoice, which used to be left open "for an operator" and was paid in
 * full afterwards for a terminated service). The F-05 shape, on the
 * subscription seam.
 *
 * So each open invoice is withdrawn through ReturnWhatAnInvoiceStillHolds:
 * what it still holds (WhatAnInvoiceStillHolds, nothing for an unpaid one) goes
 * back to the wallet recorded against it, and it is voided. A capture already
 * in flight for it lands on a void invoice and is credited to the wallet by
 * CompensateUncollectableCapture, up to what the invoice still holds of it.
 * Paying one is refused as well
 * (InvoiceNotPayableException::becauseItsSubscriptionHasEnded()), for an
 * invoice this could not void.
 *
 * Paid invoices are left as they are: what they bought was the period, or
 * the part of it, the subscription ran for.
 *
 * ---------------------------------------------------------------------------
 * Locks, and the plan an upgrade is not put back to
 * ---------------------------------------------------------------------------
 *
 * One transaction: the open invoices are locked first, in id order, then
 * `$end` moves the subscription (TransitionSubscription locks it), then each
 * invoice is withdrawn. That is the money-path lock order
 * (WhatAnInvoiceStillHolds: invoice, subscription, wallet), the order a renewal
 * and VoidInvoice take the same rows in. And because the subscription has
 * already ended when its invoices are voided, RestorePlanOnVoidedUpgrade
 * leaves it alone: an ended subscription is not moved back to the plan an
 * unpaid upgrade left, nor audited as a plan change (O-4).
 *
 * Each withdrawal runs in its own savepoint and a failure is logged rather
 * than thrown, so one invoice that cannot be voided does not undo the ending
 * or leave the invoices after it payable. What is left open for that reason
 * is refused at payment instead.
 *
 * Callers: CancelSubscription (now, and at the end of the period) and
 * {@see EndTheSubscriptionWithItsService}; {@see RestorePlanOnVoidedUpgrade}
 * is what reads the order.
 */
final readonly class WindUpAnEndedSubscription
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $end  moves the subscription to its ending; run after its invoices are locked
     * @return T what `$end` returned
     */
    public function execute(Subscription $subscription, string $why, callable $end): mixed
    {
        return DB::transaction(function () use ($subscription, $why, $end): mixed {
            $ids = Invoice::query()
                ->where('subscription_id', $subscription->getKey())
                ->where('status', InvoiceStatus::Open->value)
                ->orderBy('id')
                ->pluck('id');

            /** @var list<Invoice> $invoices */
            $invoices = [];

            foreach ($ids as $id) {
                /*
                 * Locked only while still open (LockAnInvoiceWhileOpen): a row
                 * paid since the read above is left unlocked. Locking it by id
                 * alone held a paid invoice while $end waited for the
                 * subscription, which ApplyPlanChange holds while it waits for
                 * the paid invoices a credit draws on: a deadlock
                 * (WhatAnInvoiceStillHolds, the lock order).
                 */
                $locked = LockAnInvoiceWhileOpen::take((string) $id);

                if ($locked !== null) {
                    $invoices[] = $locked;
                }
            }

            $ended = $end();

            /** @var Subscription $fresh */
            $fresh = Subscription::query()->findOrFail($subscription->getKey());

            if (! $fresh->status->isTerminal()) {
                // Nothing ended - a scheduled cancellation revoked in time, say
                // - so nothing is withdrawn.
                return $ended;
            }

            foreach ($invoices as $invoice) {
                $this->withdraw($invoice, (string) $fresh->getKey(), $why);
            }

            return $ended;
        });
    }

    private function withdraw(Invoice $invoice, string $subscriptionId, string $why): void
    {
        try {
            DB::transaction(fn (): int => $this->returnWhatItHolds->andWithdraw(
                $invoice,
                'subscription-ended',
                sprintf('Payment for invoice %s returned: the subscription ended', $invoice->number),
                'The subscription this invoice billed has ended: '.$why,
                ['subscription_id' => $subscriptionId],
            ));
        } catch (Throwable $e) {
            Log::warning('A subscription ended and one of its open invoices could not be withdrawn; paying it is refused.', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => (string) $invoice->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
