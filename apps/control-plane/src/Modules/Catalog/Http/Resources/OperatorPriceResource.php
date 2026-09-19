<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;

/**
 * One price: a plan, in one currency, for one billing period.
 *
 * Its own resource rather than an array built inside the plan's, because the
 * API description is checked against what a resource literally returns. A
 * nested structure written inline reads to that check as fields of the parent,
 * and the schema then gets written to match the reader instead of the
 * response — which is the failure mode the check exists to prevent.
 *
 * Both amounts are whole minor units, exactly as stored. Nothing here divides.
 *
 * @mixin PlanPrice
 */
final class OperatorPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanPrice $price */
        $price = $this->resource;

        return [
            'id' => (string) $price->getKey(),
            'currency' => $price->currency,
            'billing_period' => $price->billing_period,
            'recurring_amount_minor' => $price->recurring_amount_minor,
            'setup_amount_minor' => $price->setup_amount_minor,
            'is_active' => $price->is_active,
            'available_from' => $price->available_from?->toIso8601String(),
            'available_until' => $price->available_until?->toIso8601String(),
        ];
    }
}
