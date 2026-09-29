<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Queries\LockAnInvoiceWhileOpen;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
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
 * A paid renewal is left as it is: what it bought was the period, or the part
 * of it, the subscription ran for. A paid upgrade is not always that: one
 * paid in the moment before the end, whose settlement has not been heard, has
 * no resize queued and never will (ResizeOnPlanChangeSettlement suppresses it
 * on an ended subscription), so it bought nothing. That one is returned to
 * the wallet here, recorded against it ({@see ReturnAnUpgradeTheEndPrevented},
 * OA-3). One is kept when its settlement was heard while the subscription was
 * live (PlanChange::$delivered_at, whatever that settlement queued), when -
 * settled before that was recorded - a resize or package-change job keyed on
 * its invoice exists, or when a later change was settled after it (owed
 * nothing, or its invoice was paid); ReturnAnUpgradeTheEndPrevented states
 * the rule. The settlement heard after the end asks the same question, for an
 * upgrade this did not see, and the two credit it once between them.
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
 * A paid upgrade returned here is locked (lockThePaidUpgrades()) after the
 * subscription and before the wallet - an invoice lock taken after a subscription, as
 * ApplyPlanChange takes the paid invoices a credit draws on, and safe for
 * the same reason: nothing holding a paid invoice's lock waits for a
 * subscription (WhatAnInvoiceStillHolds). It is not locked with the open
 * ones before `$end`, which is exactly the hold LockAnInvoiceWhileOpen
 * exists to avoid.
 *
 * Each withdrawal (and each return of a paid upgrade) runs in its own
 * savepoint and an ordinary failure is logged rather than thrown, so one
 * invoice that cannot be voided does not undo the ending or leave the
 * invoices after it payable. What is left open for that reason is refused at
 * payment instead. A deadlock or serialization failure is not ordinary: a
 * savepoint does not recover from it (the transaction is aborted), and it is
 * rethrown so the whole ending fails rather than silently not happening
 * (rethrowWhatAbortsTheTransaction()). What happens next depends on the
 * caller. Where the wind-up is the outermost transaction - always for
 * {@see EndTheSubscriptionWithItsService}, which runs once the service's move
 * has committed, and for a scheduled cancellation ended by the sweep - the
 * whole ending is rolled back and tried again, up to ATTEMPTS times. Inside a
 * caller's transaction - an immediate cancellation, which the route records
 * atomically with its audit entry - the caller's transaction fails with it
 * and the request is refused. If every attempt fails the error reaches the
 * caller, and the listener and the sweep log it: the subscription stays as it
 * was and its open invoices open (renewal still skips a subscription whose
 * service is terminated).
 *
 * The locks: the open invoices, the subscription (in `$end`), the paid
 * upgrades, then the wallet - every invoice before the wallet.
 *
 * Callers: CancelSubscription (now, and at the end of the period) and
 * {@see EndTheSubscriptionWithItsService}; {@see RestorePlanOnVoidedUpgrade}
 * is what reads the order.
 */
final readonly class WindUpAnEndedSubscription
{
    /**
     * How many times the ending is tried when it is the outermost transaction
     * and fails on a deadlock or a serialization failure (Laravel retries only
     * then; nested, the error is thrown out to the caller's transaction).
     */
    private const int ATTEMPTS = 3;

    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
        private ReturnAnUpgradeTheEndPrevented $returnAnUpgrade,
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

            /*
             * Every invoice this touches is locked before the wallet is: the
             * open ones above, before the subscription, and the paid upgrades
             * here, after it and before any credit. Withdrawing the open
             * invoices first (which credits the wallet) and only then locking
             * a paid upgrade to return it took the wallet before an invoice,
             * and deadlocked with SettleInvoice of a second capture on that
             * upgrade (capture, invoice, wallet) - the round-five verifier's
             * race, 3/3.
             */
            $paidUpgrades = $this->lockThePaidUpgrades((string) $fresh->getKey());

            foreach ($invoices as $invoice) {
                $this->withdraw($invoice, (string) $fresh->getKey(), $why);
            }

            foreach ($paidUpgrades as $upgradeId) {
                $this->returnIfNeverDelivered($upgradeId, (string) $fresh->getKey());
            }

            return $ended;
        }, self::ATTEMPTS);
    }

    /**
     * The ids of the ended subscription's paid upgrades (proration invoices),
     * each locked, in ascending id order.
     *
     * Paid invoices locked after the subscription, as ApplyPlanChange locks
     * the paid invoices a credit draws on - safe for the same reason: nothing
     * holding a paid invoice's lock waits for a subscription
     * (WhatAnInvoiceStillHolds). They are not locked with the open ones before
     * `$end`, which is the hold LockAnInvoiceWhileOpen exists to avoid.
     *
     * @return list<string>
     */
    private function lockThePaidUpgrades(string $subscriptionId): array
    {
        $ids = Invoice::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', InvoiceStatus::Paid->value)
            ->whereHas('items', static fn ($items) => $items->where('kind', InvoiceItemKind::Proration->value))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        foreach ($ids as $id) {
            Invoice::query()->whereKey($id)->lockForUpdate()->first();
        }

        return array_values($ids);
    }

    /**
     * A paid upgrade of the ended subscription, returned to the wallet if it
     * was never delivered (ReturnAnUpgradeTheEndPrevented decides), in its own
     * savepoint for the reason a withdrawal is.
     */
    private function returnIfNeverDelivered(string $invoiceId, string $subscriptionId): void
    {
        try {
            DB::transaction(fn (): int => $this->returnAnUpgrade->execute($invoiceId));
        } catch (Throwable $e) {
            self::rethrowWhatAbortsTheTransaction($e);

            Log::warning('A subscription ended with an upgrade paid for and not delivered, and returning it to the wallet failed; the settlement heard after the end asks again.', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A savepoint recovers from an ordinary failure - Laravel rolls back to it
     * and the ending carries on - but not from a concurrency failure. On a
     * deadlock (40P01) or a serialization failure (40001) Laravel does not
     * roll back to the savepoint in a nested transaction; it rethrows, and
     * the whole transaction is left aborted (25P02). Catching that here and
     * carrying on made the outer transaction "commit" as a rollback: the
     * cancellation answered success while the subscription stayed active and
     * its open invoice stayed open (the round-five verifier's race). Those are
     * rethrown, so the whole ending fails: it is tried again when the wind-up
     * is the outermost transaction (ATTEMPTS), and otherwise fails the
     * caller's transaction (the class docblock says which callers are which).
     */
    private static function rethrowWhatAbortsTheTransaction(Throwable $e): void
    {
        $sqlState = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : null;

        if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($e)
            || in_array($sqlState, ['40P01', '40001', '25P02'], true)) {
            throw $e;
        }
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
            self::rethrowWhatAbortsTheTransaction($e);

            Log::warning('A subscription ended and one of its open invoices could not be withdrawn; paying it is refused.', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => (string) $invoice->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
