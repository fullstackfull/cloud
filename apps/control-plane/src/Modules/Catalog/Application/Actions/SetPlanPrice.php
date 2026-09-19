<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * An operator sets what a plan costs, in one currency, for one billing period.
 *
 * ===========================================================================
 * MINOR UNITS, AND NOTHING ELSE
 * ===========================================================================
 *
 * The amounts here are whole minor units — fils, cents — as integers, because
 * that is what the column is and what every total downstream is. There is no
 * decimal accepted at this boundary and no float anywhere near it: a decimal
 * is a rounding decision taken by whichever layer parses it last, and in a
 * currency with three minor units the layer that parses it last is usually the
 * one nobody tested. A screen may show 9.000 KWD; what it sends is 9000.
 *
 * The currency is checked against {@see BillingCurrencies}, which is the same
 * list registration offers and every currency-bearing request is validated
 * against. A price in a currency the platform cannot charge is a plan that
 * looks purchasable and fails at the payment step.
 *
 * ===========================================================================
 * ONE PRICE PER PLAN, CURRENCY AND PERIOD
 * ===========================================================================
 *
 * `unique(plan_id, currency, billing_period)` is in the schema, so a second
 * call for the same three is an operator changing a price rather than an
 * error — which is exactly what this action is for. The row is locked rather
 * than read, because two operators repricing the same plan in the same minute
 * would otherwise both find it absent and one of them would get a constraint
 * violation for doing their job.
 *
 * That constraint is also why a customer request cannot find two answers: the
 * database refuses the ambiguity rather than the read having to choose.
 *
 * ===========================================================================
 * WHAT CHANGING A PRICE DOES NOT DO
 * ===========================================================================
 *
 * Nothing to any order, invoice or payment that already exists. It cannot:
 * `order_items` snapshots `unit_recurring_minor`, `unit_setup_minor`, the
 * name, the billing period and the resources at the moment of sale, and
 * `plan_id` is `nullOnDelete` so the record survives the catalogue entirely.
 * The schema comment says why — "re-deriving this from the catalogue later
 * would silently rewrite history the first time a price changes" — and this
 * action is the first thing that makes that comment testable rather than
 * theoretical.
 *
 * Withdrawing a price is deactivation, never deletion, for the same reason a
 * withdrawn template is deactivated: something already sold points at it, and
 * "what was this priced at" is the first question asked when a customer
 * disputes an invoice.
 */
final readonly class SetPlanPrice
{
    public function __construct(
        private RecordActAtomically $record,
        private BillingCurrencies $currencies,
    ) {}

    /**
     * @throws CatalogueRefused
     */
    public function execute(
        Plan $plan,
        string $currency,
        BillingPeriod $period,
        int $recurringMinor,
        int $setupMinor,
        bool $isActive,
        ?CarbonImmutable $availableFrom,
        ?CarbonImmutable $availableUntil,
        User $operator,
    ): PlanPrice {
        // Throws UnsupportedBillingCurrencyException, which the platform
        // already renders; this is the one list, not a second copy of it.
        $currency = $this->currencies->assertEnabled($currency);

        if ($recurringMinor < 0) {
            throw CatalogueRefused::priceIsNegative('recurring amount');
        }

        if ($setupMinor < 0) {
            throw CatalogueRefused::priceIsNegative('setup amount');
        }

        if ($availableFrom !== null && $availableUntil !== null && $availableUntil <= $availableFrom) {
            throw CatalogueRefused::availabilityEndsBeforeItStarts();
        }

        return $this->record->execute(
            act: fn (): PlanPrice => DB::transaction(function () use (
                $plan,
                $currency,
                $period,
                $recurringMinor,
                $setupMinor,
                $isActive,
                $availableFrom,
                $availableUntil,
            ): PlanPrice {
                $existing = PlanPrice::query()
                    ->where('plan_id', $plan->getKey())
                    ->where('currency', $currency)
                    ->where('billing_period', $period->value)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'plan_id' => $plan->getKey(),
                    'currency' => $currency,
                    'billing_period' => $period->value,
                    'recurring_amount_minor' => $recurringMinor,
                    'setup_amount_minor' => $setupMinor,
                    'is_active' => $isActive,
                    'available_from' => $availableFrom,
                    'available_until' => $availableUntil,
                ];

                if ($existing === null) {
                    return PlanPrice::query()->create($attributes);
                }

                $existing->fill($attributes);
                $existing->save();

                return $existing;
            }),
            describe: fn (PlanPrice $price): AuditedAct => new AuditedAct(
                action: AuditAction::CataloguePriceSet,
                subject: $price,
                /*
                 * Both amounts, in minor units, with the currency beside them.
                 * A price change is the catalogue event an operator is most
                 * often asked to account for afterwards, and an audit entry
                 * that recorded only "a price changed" would answer none of
                 * the questions that get asked. A price is not a secret.
                 */
                context: [
                    'plan' => $plan->slug,
                    'currency' => $price->currency,
                    'billing_period' => $price->billing_period,
                    'recurring_amount_minor' => $price->recurring_amount_minor,
                    'setup_amount_minor' => $price->setup_amount_minor,
                    'is_active' => $price->is_active,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
