<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

/**
 * @mixin ProductReadiness
 */
final class ProductReadinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->product;
        /** @var ProductReadinessState $state */
        $state = $this->state;

        return [
            'product' => $product->value,
            'state' => $state->value,
            // The rung after this one, so the screen can say what the blocker
            // is a blocker TO.
            'next_state' => $state->next()?->value,
            'blocker' => $this->blocker?->value,
            'next_action' => $this->blocker?->nextAction(),
            'detail' => $this->detail,
            'depends_on' => array_map(static fn (Product $p): string => $p->value, $product->dependsOn()),
            'dependencies' => (object) ($this->dependencies ?? []),
            'requirements' => $this->requirements ?? [],
            'sellable' => [
                'declared' => $this->isDeclaredSellable(),
                'declared_at' => $this->declared_sellable_at?->toIso8601String(),
                'reason' => $this->declared_reason,
                'validation_reference' => $this->validation_reference,
                'withdrawn_at' => $this->sellability_withdrawn_at?->toIso8601String(),
                'withdrawn_reason' => $this->sellability_withdrawn_reason,
            ],
            'assessed_at' => $this->assessed_at?->toIso8601String(),
            'state_changed_at' => $this->state_changed_at?->toIso8601String(),
        ];
    }
}
