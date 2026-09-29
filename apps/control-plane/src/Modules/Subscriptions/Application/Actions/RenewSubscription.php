<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Queries\LockAnInvoiceWhileOpen;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\DTOs\BillableLine;
use Lynomia\Modules\Subscriptions\Application\DTOs\RenewalPlan;
use Lynomia\Modules\Subscriptions\Application\Queries\UnpaidUpgrade;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionNotRenewableException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Repositories\CouponTermsRepository;

/**
 * Advances a subscription into its next period and describes what to bill for
 * it.
 *
 * The action deliberately stops short of issuing anything. It moves the period
 * and returns a RenewalPlan; the coordinator prices it and hands it to
 * IssueInvoice. Two reasons: the period advance must be atomic with the lock
 * that makes it idempotent, and invoice numbering, tax and discount allocation
 * belong to billing.
 *
 * Idempotency is the whole difficulty. A renewal worker is re-run after a
 * deploy, a queue redelivers, an operator triggers a sweep by hand — and the
 * cost of getting it wrong is a customer billed twice for one month. Three
 * things together prevent that:
 *
 *  1. the subscription row is locked for the duration, so two workers
 *     serialise rather than interleave;
 *  2. the locked row's current_period_end is compared against the one the
 *     caller read, so a worker holding a stale copy cannot advance a period
 *     that has already moved;
 *  3. the new period is due strictly in the future, so the second run of the
 *     same minute finds nothing due and returns null.
 */
final readonly class RenewSubscription
{
    public function __construct(
        private CouponTermsRepository $coupons,
        private UnpaidUpgrade $unpaid,
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    /**
     * @return RenewalPlan|null null when the period has already been advanced,
     *                          which is how a duplicate worker run converges
     *
     * @throws SubscriptionNotRenewableException
     */
    public function execute(Subscription $subscription, ?DateTimeImmutable $at = null): ?RenewalPlan
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $now): ?RenewalPlan {
            /*
             * An upgrade still unpaid at the renewal lapses (below), which
             * voids its invoice. VoidInvoice locks the invoice and then the
             * subscription; the invoice is locked here first, before the
             * subscription, so a renewal and an operator's void of the same
             * invoice take the two rows in the same order - and an invoice
             * this did not lock first is not lapsed in this attempt (below).
             *
             * Locked only while it is still open (LockAnInvoiceWhileOpen): a
             * row paid in the meantime is left unlocked. Found open by an unlocked read and then locked by its id,
             * an invoice paid in between was held here as a paid invoice
             * while this waited for the subscription - and ApplyPlanChange,
             * holding the subscription, waits for the paid invoices a
             * downgrade credit draws on: a deadlock, measured across processes
             * (the round-four verifier's dl.sh; ARenewalAndAPlanChangeDoNotDeadlockTest).
             */
            $lapsing = $this->unpaid->openInvoiceOf($subscription);

            $lockedFirst = $lapsing === null
                ? null
                : LockAnInvoiceWhileOpen::take((string) $lapsing->getKey());

            /** @var Subscription $locked */
            $locked = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            $this->assertRenewable($locked);

            $unbillable = $this->serviceNothingIsOwedFor($locked);

            if ($unbillable !== null) {
                /*
                 * Skipped rather than refused. The subscription is perfectly
                 * renewable in its own terms — active, auto-renewing, due —
                 * and what is wrong is on the other side of it: the service it
                 * pays for was never delivered, or has ended.
                 *
                 * Returning null puts this in the sweep's `skipped` count,
                 * which is where "nothing to do here" belongs. Throwing would
                 * put it in `failed` and raise an alarm about the renewal
                 * machinery, when the machinery is working and the service is
                 * the problem.
                 */
                Log::info(
                    $unbillable->status === ServiceStatus::Terminated
                        ? 'A renewal was skipped: the service it pays for has ended.'
                        : 'A renewal was skipped: the service it pays for was never delivered.',
                    [
                        'subscription_id' => (string) $locked->getKey(),
                        'customer_id' => (string) $locked->customer_id,
                        'service_id' => (string) $unbillable->getKey(),
                        'status' => $unbillable->status->value,
                        'reason' => ((array) $unbillable->resources)['placement_blocked_reason'] ?? null,
                    ],
                );

                return null;
            }

            /*
             * The caller's copy was read before the lock. If the period has
             * moved since, another worker has already renewed this
             * subscription and this run must produce nothing — not a second
             * invoice for a period that is already paid for.
             */
            if (! $locked->current_period_end->equalTo($subscription->current_period_end)) {
                return null;
            }

            $dueAt = $locked->next_invoice_at ?? $locked->current_period_end;

            if ($dueAt->greaterThan($now)) {
                return null;
            }

            /*
             * An upgrade whose invoice is not paid in full by the time the next
             * period is billed lapses - here, when the renewal is issued, which
             * is at next_invoice_at and so may be before the period's end if
             * this subscription invoices ahead. In this transaction, before the
             * new period is billed: whatever was paid on the invoice goes back
             * to the wallet - now, what it still holds; and what a refund in
             * flight is returning, by that refund, or, if the refund fails, to
             * the wallet when the failure is recorded - the invoice is voided,
             * and the subscription goes back to the plan it has paid for.
             *
             * Renewing it instead - at either amount - let the customer pay
             * the small proration invoice after the renewal and be built a
             * whole period of the bigger plan, month after month (re-audit,
             * B1). Leaving a part-paid invoice alone did the same with one fils
             * paid first (re-audit, n06). A void invoice cannot be paid, so the
             * upgrade cannot be revived afterwards.
             */
            $open = $this->unpaid->openInvoiceOf($locked);

            /*
             * Lapsed only if it is the invoice locked above, before the
             * subscription. One that appeared since - an upgrade committed
             * between the unlocked read and the subscription's lock - is not
             * locked here, after the subscription: an operator voiding that
             * invoice holds it and waits for the subscription (VoidInvoice's
             * RestorePlanOnVoidedUpgrade), and the two deadlocked (the
             * verifier's dl2, on the round-three base too;
             * ARenewalAndAPlanChangeDoNotDeadlockTest). This attempt ends
             * without renewing - nothing has been written - and is counted
             * skipped; the next sweep finds the invoice first and locks it
             * before the subscription.
             */
            if ($open !== null && (string) $open->getKey() !== (string) $lockedFirst?->getKey()) {
                Log::info('A renewal was put off: an upgrade invoice appeared after it looked; the next sweep lapses it.', [
                    'subscription_id' => (string) $locked->getKey(),
                    'invoice_id' => (string) $open->getKey(),
                ]);

                return null;
            }

            if ($open !== null) {
                $this->lapse($open);

                /** @var Subscription $locked */
                $locked = Subscription::query()->with('plan')->findOrFail($locked->getKey());
            }

            // Measured before anything moves: how far ahead of the period end
            // this subscription invoices.
            $lead = $locked->next_invoice_at === null
                ? 0
                : max(0, $locked->current_period_end->getTimestamp() - $locked->next_invoice_at->getTimestamp());

            // Periods are contiguous: the new one starts where the old one
            // ended, never at "now". Starting at now would give the customer a
            // gap they paid for and shift every future anniversary by however
            // late the worker happened to run.
            $periodStart = $locked->current_period_end;
            $periodEnd = $locked->billing_period->advance($periodStart);

            $coupon = $this->coupons->find($locked->coupon_id);
            $percentage = null;
            $fixed = null;
            $code = null;

            if ($coupon !== null && $coupon->appliesTo($locked->coupon_cycles_remaining)) {
                $percentage = $coupon->percentage;
                $fixed = $coupon->fixedAmountIn($locked->currency);
                $code = $coupon->code;
            }

            $discountApplied = $percentage !== null || $fixed !== null;

            $cyclesRemaining = $locked->coupon_cycles_remaining;

            // A cycle is consumed only when the coupon actually reduced this
            // renewal. A coupon in another currency, or one that has run out,
            // must not silently burn cycles the customer still has.
            if ($discountApplied && $cyclesRemaining !== null) {
                $cyclesRemaining--;
            }

            $locked->current_period_start = $periodStart;
            $locked->current_period_end = $periodEnd;
            $locked->next_invoice_at = $this->nextInvoiceAt($periodEnd, $lead, $now);
            $locked->coupon_cycles_remaining = $cyclesRemaining;
            $locked->save();

            return new RenewalPlan(
                subscriptionId: (string) $locked->getKey(),
                customerId: (string) $locked->customer_id,
                currency: $locked->currency,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                lines: [$this->renewalLine($locked)],
                fixedDiscount: $fixed,
                percentageDiscount: $percentage,
                couponCode: $discountApplied ? $code : null,
                couponCyclesRemaining: $cyclesRemaining,
            );
        });
    }

    /**
     * The renewal bills the plan the subscription has paid for.
     *
     * That is the plan it is on, except while the plan change that moved it
     * there is still waiting on its invoice. An upgrade moves the recurring
     * amount the moment it is confirmed and hands over nothing until its
     * invoice settles; renewing at the new amount before then billed a whole
     * period of the bigger plan for a machine still running the smaller one,
     * and left the customer two open invoices for one upgrade. An upgrade
     * with an open invoice lapses before this is reached (see execute()); one
     * whose invoice was never paid and is not open - uncollectible, or voided
     * without being undone - is billed at what the subscription carried
     * before the change. Once its invoice is paid, the next renewal bills the
     * new plan.
     */
    private function renewalLine(Subscription $subscription): BillableLine
    {
        $unpaid = $this->unpaid->onto($subscription);

        $billed = $unpaid === null
            ? $subscription->plan
            : Plan::query()->find($unpaid->from_plan_id);

        $description = $billed?->nameFor(app()->getLocale()) ?? 'Subscription renewal';

        return new BillableLine(
            InvoiceItemKind::Plan,
            new PricingLine(
                description: $description,
                quantity: 1,
                unitPrice: $unpaid === null
                    ? $subscription->recurringAmount()
                    : Money::ofMinor((int) $unpaid->from_recurring_amount_minor, $subscription->currency),
                // Never on a renewal: standing the service up was a one-off
                // charged with the first order.
                setupFee: Money::zero($subscription->currency),
            ),
        );
    }

    /**
     * Return what a lapsing upgrade's invoice still holds, then void it.
     *
     * Through ReturnWhatAnInvoiceStillHolds, which reads WhatAnInvoiceStillHolds
     * under the invoice's lock and credits it to the wallet as a top-up
     * recorded against the invoice - what stops the same money also being
     * refunded to the card afterwards. This used to compute its own figure
     * from the document, `amount_paid - amount_refunded - top-ups`, which does
     * not see a refund until the queued RecordRefundAgainstTheInvoice books
     * it: a card refund still pending at the provider, or a wallet refund of a
     * wallet part-payment (whose credit is kind refund, not top-up), both
     * returned the money a second time here - 10.000 back for 5.000 paid - and
     * the refund's own booking then failed into failed_jobs (N-1).
     */
    private function lapse(Invoice $invoice): void
    {
        $this->returnWhatItHolds->andWithdraw(
            $invoice,
            'lapsed-upgrade',
            sprintf('Payment for invoice %s returned: the upgrade lapsed unpaid', $invoice->number),
            'The upgrade was not paid for in full before the next period was billed.',
            ['reason' => 'plan_change_lapsed'],
        );
    }

    /**
     * Keeps whatever lead time the subscription was invoicing with.
     *
     * next_invoice_at may sit before current_period_end so the customer
     * receives the invoice before the service continues. The lead is not
     * stored anywhere, so it is measured from the period being left and
     * re-applied to the new one — and clamped, because a lead longer than the
     * period would leave the next invoice already due and let a single worker
     * pass advance the same subscription forever.
     */
    private function nextInvoiceAt(CarbonImmutable $periodEnd, int $lead, CarbonImmutable $now): CarbonImmutable
    {
        $next = $periodEnd->subSeconds($lead);

        return $next->greaterThan($now) ? $next : $periodEnd;
    }

    /**
     * The service this subscription pays for, when no further period is owed
     * for it.
     *
     * Three cases, each a service that holds nothing on the customer's behalf
     * (`ServiceStatus::holdsResources()` is false for all three), so billing a
     * second period for it would charge rent on an empty room:
     *
     *  - PENDING *and* carrying a placement-blocked reason: the platform never
     *    got as far as asking a provider for anything.
     *  - FAILED: the build was asked for and did not succeed. The state
     *    machine reaches FAILED only from PROVISIONING, never from ACTIVE, so
     *    a FAILED service was never delivered. This used to renew, on the
     *    reasoning that an operator might retry, refund or end it — true, and
     *    beside the point: none of those makes the period just ended one the
     *    customer received (F-07, as the re-audit after round two measured
     *    it: `considered=1 renewed=1` for a FAILED, never-activated service).
     *    A retried build that succeeds makes the service ACTIVE, and the next
     *    sweep bills as usual.
     *  - TERMINATED: the service has ended. Ending it ends the subscription
     *    too (EndTheSubscriptionWithItsService); this is the backstop for a
     *    row ended before that listener existed, or one it could not move
     *    (I-1).
     *
     * PENDING *without* a blocked reason is left alone: it is a build a
     * worker has not reached yet, and a queue a few seconds behind is not a
     * delivery failure. PROVISIONING, ACTIVE, SUSPENDED and REACTIVATING all
     * hold real resources and keep billing.
     */
    private function serviceNothingIsOwedFor(Subscription $subscription): ?Service
    {
        /** @var Service|null $service */
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        if ($service === null) {
            return null;
        }

        if (in_array($service->status, [ServiceStatus::Failed, ServiceStatus::Terminated], true)) {
            return $service;
        }

        if ($service->status !== ServiceStatus::Pending) {
            return null;
        }

        $resources = (array) $service->resources;

        return ($resources['placement_blocked_reason'] ?? null) !== null ? $service : null;
    }

    /**
     * @throws SubscriptionNotRenewableException
     */
    private function assertRenewable(Subscription $subscription): void
    {
        if (! $subscription->status->shouldRenew()) {
            throw SubscriptionNotRenewableException::forStatus(
                (string) $subscription->getKey(),
                $subscription->status,
            );
        }

        if (! $subscription->auto_renew) {
            throw SubscriptionNotRenewableException::becauseAutoRenewIsOff((string) $subscription->getKey());
        }

        if ($subscription->isScheduledToCancel()) {
            throw SubscriptionNotRenewableException::becauseCancellationIsScheduled((string) $subscription->getKey());
        }
    }
}
