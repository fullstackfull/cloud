<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\DTOs\BillableLine;
use Lynomia\Modules\Subscriptions\Application\DTOs\RenewalPlan;
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
            /** @var Subscription $locked */
            $locked = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            $this->assertRenewable($locked);

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

    private function renewalLine(Subscription $subscription): BillableLine
    {
        $description = $subscription->plan?->nameFor(app()->getLocale()) ?? 'Subscription renewal';

        return new BillableLine(
            InvoiceItemKind::Plan,
            new PricingLine(
                description: $description,
                quantity: 1,
                unitPrice: $subscription->recurringAmount(),
                // Never on a renewal: standing the service up was a one-off
                // charged with the first order.
                setupFee: Money::zero($subscription->currency),
            ),
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
