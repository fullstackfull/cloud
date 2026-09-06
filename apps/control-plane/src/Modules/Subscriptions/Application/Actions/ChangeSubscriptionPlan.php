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
        ?DateTimeImmutable $changeAt = null,
    ): ProrationPlan {
        if ($newPrice->plan_id !== $newPlan->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Price %s does not belong to plan %s.',
                (string) $newPrice->getKey(),
                (string) $newPlan->getKey(),
            ));
        }

        $now = $changeAt !== null ? CarbonImmutable::instance($changeAt) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $newPlan, $newPrice, $now): ProrationPlan {
            /** @var Subscription $locked */
            $locked = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            $this->assertChangeable($locked, $newPrice);

            $periodStart = $locked->current_period_start;
            $periodEnd = $locked->current_period_end;

            $credit = $this->pricing
                ->prorate($locked->recurringAmount(), $periodStart, $periodEnd, $now)
                ->negated();

            $charge = $this->pricing->prorate($newPrice->recurring(), $periodStart, $periodEnd, $now);

            $outgoingPlan = $locked->plan?->nameFor(app()->getLocale()) ?? 'previous plan';
            $incomingPlan = $newPlan->nameFor(app()->getLocale());

            $locked->plan_id = $newPlan->getKey();
            $locked->recurring_amount_minor = $newPrice->recurring_amount_minor;
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
