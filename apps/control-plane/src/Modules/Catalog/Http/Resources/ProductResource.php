<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Http\Support\RequestLocale;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * A product family as a customer sees it.
 *
 * is_active and is_public are absent rather than always-true: they are the
 * merchandising switches, and a customer who never sees them cannot learn
 * anything from them. Everything reachable through this resource has already
 * passed both.
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = RequestLocale::for($request);

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'slug' => $this->slug,
            'name' => $this->nameFor($locale),
            'description' => RequestLocale::resolve($this->description, $locale),

            'plan_count' => $this->whenCounted('plans'),
            'plans' => PlanResource::collection($this->whenLoaded('plans')),
        ];
    }
}
