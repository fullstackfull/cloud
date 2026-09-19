<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * A product as an operator sees it: both languages, and both switches.
 *
 * Distinct from {@see ProductResource}, which is the customer surface and
 * shows one locale and only what is on sale. An operator needs to see the row
 * that is switched off — "why is this not being offered" is the question the
 * screen exists to answer, and a list that hid inactive products could not.
 *
 * @mixin Product
 */
final class OperatorProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => (string) $product->getKey(),
            'kind' => $product->kind->value,
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
            'is_active' => $product->is_active,
            'is_public' => $product->is_public,
            'sort_order' => $product->sort_order,
            'plans_count' => $this->whenCounted('plans'),
        ];
    }
}
