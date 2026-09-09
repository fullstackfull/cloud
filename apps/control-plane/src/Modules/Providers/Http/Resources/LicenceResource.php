<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;

/**
 * A licence as the control centre shows it.
 *
 * `permits` and `needs_attention` are here as booleans rather than left for a
 * screen to derive from the state, because the derivation is the domain's:
 * Expiring still permits and still needs attention, and a screen that had to
 * know that would be a second copy of LicenceState.
 *
 * @mixin Licence
 */
final class LicenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->product,
            'licence_type' => $this->licence_type,
            'environment' => $this->environment->value,
            'state' => $this->state->value,
            'permits' => $this->state->permits(),
            'needs_attention' => $this->state->needsAttention(),
            'days_remaining' => $this->daysRemaining(),

            'starts_on' => $this->starts_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'renews_on' => $this->renews_on?->toDateString(),
            'seats' => $this->seats,
            'external_reference' => $this->external_reference,

            'server' => $this->whenLoaded('server', fn (): ?array => $this->server === null ? null : [
                'id' => $this->server->id,
                'server_name' => $this->server->name,
            ]),
            'credential' => $this->whenLoaded('credential', fn (): ?array => $this->credential === null ? null : [
                'id' => $this->credential->id,
                'credential_name' => $this->credential->name,
                'credential_state' => $this->credential->state->value,
            ]),
            'usage' => [
                'providers' => (int) ($this->provider_instances_count ?? 0),
            ],

            'invalidated_at' => $this->invalidated_at?->toIso8601String(),
            'invalidated_reason' => $this->invalidated_reason,
            'renewed_at' => $this->renewed_at?->toIso8601String(),
            'state_changed_at' => $this->state_changed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
