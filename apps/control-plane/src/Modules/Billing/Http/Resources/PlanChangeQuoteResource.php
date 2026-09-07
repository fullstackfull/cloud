<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeQuote;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;

/**
 * One priced answer to "what if I moved to this plan".
 *
 * Every money field is the platform's own Money serialisation — minor units
 * and a currency, never a float and never a formatted string — for the same
 * reason it is everywhere else in this API: a client that receives 12.75 has
 * received a number it cannot add to another one safely, and a client that
 * receives "12.750 KWD" has received a sentence it will try to parse.
 *
 * `refusals` is a list of stable keys rather than sentences. The portal owns
 * the wording, in two languages, and a refusal explained by the API in English
 * is a refusal an Arabic-speaking customer reads in English.
 *
 * @mixin PlanChangeQuote
 */
final class PlanChangeQuoteResource extends JsonResource
{
    public function __construct(PlanChangeQuote $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanChangeQuote $quote */
        $quote = $this->resource;

        $available = $quote->isAvailable();

        return [
            'plan_id' => $quote->planId,
            'price_id' => $quote->priceId,
            'plan_name' => $quote->planName,

            'is_available' => $available,
            'refusals' => array_map(
                static fn (PlanChangeRefusal $refusal): string => $refusal->value,
                $quote->refusals,
            ),
            'warnings' => $quote->warnings,

            'current_recurring' => $quote->currentRecurring->jsonSerialize(),
            'new_recurring' => $quote->newRecurring->jsonSerialize(),

            /*
             * Published only for a plan that can actually be taken. A price
             * shown against a refused plan is a price a customer will decide
             * on and then be told they cannot have.
             */
            'credit' => $available ? $quote->credit->jsonSerialize() : null,
            'charge' => $available ? $quote->charge->jsonSerialize() : null,
            'amount_due_now' => $available ? $quote->amountDueNow->jsonSerialize() : null,

            'effective_at' => $quote->effectiveAt->toIso8601String(),
            'period_end' => $quote->periodEnd->toIso8601String(),

            'current_resources' => $quote->currentResources->toArray(),
            'new_resources' => $quote->newResources->toArray(),

            /*
             * Whether the customer's server has to change as well as their
             * invoice. The portal says so before they confirm, because a
             * resize interrupts a running machine and "your bill changes" and
             * "your server restarts" are different decisions.
             */
            'changes_infrastructure' => $quote->changesInfrastructure,
        ];
    }
}
