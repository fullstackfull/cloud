<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Services;

use Illuminate\Support\Collection;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;

/**
 * Which of a plan's prices a given customer is allowed to be quoted.
 *
 * Two rules, and they are the same two rules the checkout applies:
 *
 *  1. One currency only — the customer's own. The prices in other currencies
 *     are omitted rather than converted, because the platform sets a price per
 *     currency deliberately and a converted price is a price nobody set. A
 *     browse endpoint that converted would quote a number the order could not
 *     honour.
 *  2. Availability is decided by PlanPrice::isAvailable(), not by a second
 *     copy of the same predicate written in SQL. A price that has been retired
 *     or whose window has not opened must be invisible here for exactly the
 *     same reason it is unbuyable at checkout, and the only way to guarantee
 *     "exactly the same reason" is to call the same method.
 */
final class CataloguePriceVisibility
{
    /**
     * @return Collection<int, PlanPrice>
     */
    public function visible(Plan $plan, string $currency): Collection
    {
        $currency = strtoupper($currency);

        return $plan->prices
            ->filter(static fn (PlanPrice $price): bool => strtoupper($price->currency) === $currency
                && $price->isAvailable())
            ->sortBy(static fn (PlanPrice $price): float => $price->billing_period->approximateMonths())
            ->values();
    }

    /**
     * Replaces the loaded relation with the visible subset, so nothing
     * downstream — a resource, a log line, a future caller — can reach a price
     * the customer was never meant to see.
     */
    public function apply(Plan $plan, string $currency): Plan
    {
        $plan->setRelation('prices', $this->visible($plan, $currency));

        return $plan;
    }
}
