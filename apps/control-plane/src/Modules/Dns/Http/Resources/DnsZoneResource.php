<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * One zone, as the account holding it may see it.
 *
 * Deliberately absent: the provider's own zone identifier and the provider's
 * name. Neither helps a customer do anything, and together they say which
 * third party this platform buys DNS from — which is the platform's business
 * arrangement, not the customer's.
 *
 * `nameservers` is the whole product here. Until the domain is delegated to
 * them at the registrar, this zone serves nothing at all, and no state on this
 * payload should be read as "your domain is working" — which is why the zone's
 * own state is published beside them rather than instead of them.
 *
 * @mixin DnsZone
 */
final class DnsZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_id' => $this->service_id,
            'state' => $this->state->value,

            // The three questions an account has about a zone: is it ready,
            // is it going, and does somebody need to look at it.
            'is_live' => $this->state->isLive(),
            'is_being_deleted' => $this->state->isBeingDeleted(),
            'needs_attention' => $this->state->needsAttention(),

            'nameservers' => $this->nameservers ?? [],

            /*
             * The provider's refusal, already redacted where it was stored. A
             * customer whose zone was refused needs to see why — most refusals
             * are "somebody else already holds this domain here", which they
             * can act on.
             */
            'failure_reason' => $this->failure_reason,

            'record_count' => $this->whenCounted('liveRecords'),

            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
