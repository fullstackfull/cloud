<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Http\Support\RequestLocale;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;

/**
 * A purchasable configuration.
 *
 * `resources` is published because it is the whole substance of what is being
 * sold — vCPU, memory, disk — and a customer cannot choose between plans
 * without it. `placement_constraints` is not, and that is the important half:
 * it describes the platform's own infrastructure (storage classes, node
 * capabilities, which racks can host this plan) and is a map of the estate for
 * anyone reading it. Nothing a customer can act on lives in it.
 *
 * The limits are published because a customer can act on them: knowing a plan
 * is capped at two per account is the difference between an informed order and
 * a rejected one. Null means unlimited, and is sent as null rather than
 * omitted so a client can tell "no limit" from "not loaded".
 *
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = RequestLocale::for($request);

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'slug' => $this->slug,
            'name' => $this->nameFor($locale),
            'description' => RequestLocale::resolve($this->description, $locale),
            'resources' => $this->resources,
            'stock_limit' => $this->stock_limit,
            'per_customer_limit' => $this->per_customer_limit,

            'prices' => PlanPriceResource::collection($this->whenLoaded('prices')),
            'product' => ProductResource::make($this->whenLoaded('product')),
        ];
    }
}
