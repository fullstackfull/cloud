<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\DTOs\BillableLine;
use Lynomia\Modules\Subscriptions\Application\DTOs\ProrationPlan;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\IncompatibleBillingPeriodException;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionNotChangeableException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Moves a running subscription onto another plan, mid-cycle.
 *
 * The customer has already paid for the whole of the current period, so the
 * change is settled as two halves: the unused remainder of the plan they are
 * leaving is credited, and the same remainder is charged at the plan they are
 * moving to. Both go through PricingEngine::prorate(), against the same period
 * and the same instant, which is what makes the arithmetic reversible — an
 * upgrade and an immediate downgrade produce the same fraction twice with
 * opposite signs and net to exactly zero, rather than leaking a fils on every
 * change.
 *
 * The period boundaries themselves never move. A plan change is not a renewal:
 * the customer keeps their billing anniversary, and the next invoice arrives
 * when it always would have.
 */
final readonly class ChangeSubscriptionPlan
{
    public function __construct(
        private PricingEngine $pricing,
    ) {}

    /**
     * @throws SubscriptionNotChangeableException
     * @throws IncompatibleBillingPeriodException
     * @throws CurrencyMismatchException
     */
    public function execute(
        Subscription $subscription,
        Plan $newPlan,
        PlanPrice $newPrice,
        ?int $units = null,
        ?DateTimeImmutable $changeAt = null,
    ): ProrationPlan {
        if ($newPrice->plan_id !== $newPlan->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Price %s does not belong to plan %s.',
                (string) $newPrice->getKey(),
                (string) $newPlan->getKey(),
            ));
        }

        if ($units !== null && $units < 1) {
            throw new InvalidArgumentException('A subscription cannot be moved onto fewer than one unit of a plan.');
        }

        $now = $changeAt !== null ? CarbonImmutable::instance($changeAt) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $newPlan, $newPrice, $units, $now): ProrationPlan {
            /** @var Subscription $locked */
            $locked = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            $this->assertChangeable($locked, $newPrice);

            $periodStart = $locked->current_period_start;
            $periodEnd = $locked->current_period_end;

            /*
             * A subscription bills for a whole order line, so its recurring
             * amount is the plan's unit price times however many units were
             * bought. The row does not carry that count, so it has to be
             * re-established before the new plan is priced — moving a
             * three-server subscription onto the unit price of the new plan
             * would quietly bill a third of what the customer is using, every
             * period, for the life of the subscription.
             */
            $count = $units ?? $this->unitsOn($locked);
            $newRecurring = $newPrice->recurring()->multipliedBy($count);

            $credit = $this->pricing
                ->prorate($locked->recurringAmount(), $periodStart, $periodEnd, $now)
                ->negated();

            $charge = $this->pricing->prorate($newRecurring, $periodStart, $periodEnd, $now);

            $outgoingPlan = $locked->plan?->nameFor(app()->getLocale()) ?? 'previous plan';
            $incomingPlan = $newPlan->nameFor(app()->getLocale());

            $locked->plan_id = $newPlan->getKey();
            $locked->recurring_amount_minor = $newRecurring->minorUnits();
            $locked->save();

            return new ProrationPlan(
                subscriptionId: (string) $locked->getKey(),
                currency: $locked->currency,
                changeAt: $now,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                credit: $credit,
                charge: $charge,
                lines: [
                    new BillableLine(
                        InvoiceItemKind::Credit,
                        $this->prorationLine('Unused time on '.$outgoingPlan, $credit),
                    ),
                    new BillableLine(
                        InvoiceItemKind::Proration,
                        $this->prorationLine('Remainder of period on '.$incomingPlan, $charge),
                    ),
                ],
            );
        });
    }

    /**
     * Proration lines are never discountable.
     *
     * A coupon is a discount on the recurring price, and it was already
     * applied to the invoice that charged for this period. Letting it reduce
     * these lines would discount the credit as well as the charge — returning
     * the customer less than they actually paid for the time they did not use.
     */
    private function prorationLine(string $description, Money $amount): PricingLine
    {
        return new PricingLine(
            description: $description,
            quantity: 1,
            unitPrice: $amount,
            setupFee: Money::zero($amount->currency()),
            discountable: false,
        );
    }

    /**
     * How many units of its current plan a subscription is paying for.
     *
     * plan_prices is unique on (plan, currency, period), so the outgoing unit
     * price is unambiguous and the count is a division. It is only accepted
     * when it divides exactly: a subscription on a grandfathered price no
     * longer in the catalogue would otherwise silently resolve to one unit and
     * under-bill the customer forever, so the caller is made to state the count
     * instead.
     *
     * @throws SubscriptionNotChangeableException
     */
    private function unitsOn(Subscription $subscription): int
    {
        // Nothing in a zero-priced row distinguishes one free unit from ten,
        // so a move off a free plan bills for one unless the caller says
        // otherwise.
        if ($subscription->recurring_amount_minor === 0) {
            return 1;
        }

        $unit = PlanPrice::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('currency', $subscription->currency)
            ->where('billing_period', $subscription->billing_period->value)
            ->value('recurring_amount_minor');

        $unit = is_numeric($unit) ? (int) $unit : 0;

        if ($unit > 0 && $subscription->recurring_amount_minor % $unit === 0) {
            return intdiv($subscription->recurring_amount_minor, $unit);
        }

        throw SubscriptionNotChangeableException::becauseUnitCountIsUnknown(
            (string) $subscription->getKey(),
        );
    }

    /**
     * @throws SubscriptionNotChangeableException
     * @throws IncompatibleBillingPeriodException
     * @throws CurrencyMismatchException
     */
    private function assertChangeable(Subscription $subscription, PlanPrice $newPrice): void
    {
        if (! $subscription->status->serviceShouldRun()) {
            throw SubscriptionNotChangeableException::forStatus(
                (string) $subscription->getKey(),
                $subscription->status,
            );
        }

        // Never converted, so a plan sold only in another currency is simply
        // not a plan this subscription can move to.
        if ($newPrice->currency !== $subscription->currency) {
            throw CurrencyMismatchException::between($subscription->currency, $newPrice->currency);
        }

        if ($newPrice->billing_period !== $subscription->billing_period) {
            throw IncompatibleBillingPeriodException::between(
                (string) $subscription->getKey(),
                $subscription->billing_period,
                $newPrice->billing_period,
            );
        }
    }
}
