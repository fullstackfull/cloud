<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;

/**
 * @mixin CountryCurrencyChange
 */
final class CountryCurrencyChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'state' => $this->state->value,
            'is_open' => $this->state->isOpen(),
            'needs_attention' => $this->state->needsAttention(),
            'from_country' => $this->from_country,
            'to_country' => $this->to_country,
            'from_currency' => $this->from_currency,
            'to_currency' => $this->to_currency,
            'reason' => $this->reason,
            'impact' => $this->impact,
            'decision_note' => $this->decision_note,
            'analysed_at' => $this->analysed_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
