<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;

/**
 * A plan as an operator sees it, with every price beside it.
 *
 * `priced_in` is the field the Admin screen needs and nothing else supplies:
 * a plan that is active, public and priced in no currency is purchasable by
 * nobody, and it looks identical to a working plan in a list that shows only
 * the two switches. Naming the currencies makes the hole visible without the
 * screen re-deriving it.
 *
 * @mixin Plan
 */
final class OperatorPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Plan $plan */
        $plan = $this->resource;

        /** @var list<PlanPrice> $prices */
        $prices = $plan->relationLoaded('prices') ? $plan->prices->all() : [];

        return [
            'id' => (string) $plan->getKey(),
            'product_id' => (string) $plan->product_id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'resources' => $plan->resources,
            'placement_constraints' => $plan->placement_constraints,
            'stock_limit' => $plan->stock_limit,
            'per_customer_limit' => $plan->per_customer_limit,
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
            'sort_order' => $plan->sort_order,
            /*
             * A collection rather than `->toArray()` on each: the description
             * check treats a resource merged with `->toArray(` as publishing
             * the parent's fields, and these are a nested list under a key of
             * their own.
             */
            'prices' => $this->when(
                $plan->relationLoaded('prices'),
                fn (): mixed => OperatorPriceResource::collection($prices),
            ),
            'priced_in' => $this->when(
                $plan->relationLoaded('prices'),
                fn (): array => array_values(array_unique(array_map(
                    static fn (PlanPrice $price): string => $price->currency,
                    array_filter($prices, static fn (PlanPrice $price): bool => $price->is_active),
                ))),
            ),
        ];
    }
}
