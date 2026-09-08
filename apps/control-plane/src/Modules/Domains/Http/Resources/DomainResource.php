<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;

/**
 * One name, as the account holding it may see it.
 *
 * `is_expiring` is computed here from the date rather than read from a stored
 * flag, because there is no stored flag: an "expiring" column would be a
 * second copy of `expires_at` that is wrong for as long as the sweep
 * maintaining it is late, and the symptom would be a customer not warned.
 *
 * Deliberately absent: the registrar's name and its reference, and the
 * contacts. The first two are the platform's business arrangement; the
 * contacts are personal data with their own endpoint and their own permission.
 *
 * @mixin Domain
 */
final class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tld' => $this->tld,
            'state' => $this->state->value,

            // The four questions an account has about a name: is it mine, is
            // it working, is it going, and does somebody need to look at it.
            'is_held' => $this->state->isHeld(),
            'is_manageable' => $this->state->isManageable(),
            'is_renewable' => $this->state->isRenewable(),
            'needs_attention' => $this->state->needsAttention(),

            'term_years' => $this->term_years,
            'auto_renew' => $this->auto_renew,
            'transfer_locked' => $this->transfer_locked,
            'nameservers' => $this->nameservers ?? [],
            'dns_zone_id' => $this->dns_zone_id,

            'registered_at' => $this->registered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expiring' => $this->isExpiringWithin(
                (int) config('domains.renewal.warn_days', 45),
            ),

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
