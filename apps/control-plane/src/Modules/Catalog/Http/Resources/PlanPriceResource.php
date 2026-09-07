<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;

/**
 * A price the customer could actually be charged.
 *
 * Money is an integer number of minor units plus its ISO-4217 code, with the
 * decimal string alongside for display. Never a float: a JSON number would let
 * every client in the chain re-round it, and 9.000 KWD is three minor digits
 * that must survive the round trip exactly.
 *
 * @mixin PlanPrice
 */
final class PlanPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'billing_period' => $this->billing_period->value,
            'currency' => $this->currency,
            'recurring' => $this->recurring()->jsonSerialize(),
            'setup' => $this->setup()->jsonSerialize(),

            // is_active, available_from and available_until are absent on
            // purpose: an unavailable price never reaches this resource, so
            // publishing the window would only describe the merchandising
            // calendar to people it is aimed at.
        ];
    }
}
