<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'display_name' => $this->display_name,
            'legal_name' => $this->legal_name,
            'currency' => $this->currency,
            'country' => $this->country,
            'can_purchase' => $this->canPurchase(),

            // The caller's role inside this account, when loaded through the
            // membership pivot.
            'role' => $this->whenPivotLoaded('customer_members', fn (): ?string => $this->pivot?->role?->value),

            // internal_notes and requires_manual_review are deliberately absent:
            // they are operator-facing and must never reach a customer.
        ];
    }
}
