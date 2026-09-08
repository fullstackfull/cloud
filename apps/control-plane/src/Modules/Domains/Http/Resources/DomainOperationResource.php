<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;

/**
 * A registration, renewal or transfer in progress.
 *
 * `needs_attention` is published rather than left to the client to infer from
 * the state string. A customer whose registration timed out at the registrar
 * is in the one situation where they must not be told to try again, and a
 * screen that worked that out for itself from a list of state names would get
 * it wrong the first time a state was added.
 *
 * Deliberately absent: `cost_minor`, and the registrar's own name and
 * reference. What this platform pays and who it buys from are not the
 * customer's business, and the reference is a support tool.
 *
 * @mixin DomainOperation
 */
final class DomainOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'state' => $this->state->value,
            'term_years' => $this->term_years,
            'currency' => $this->currency,
            'price_minor' => $this->price_minor,
            'invoice_id' => $this->invoice_id,

            'is_in_flight' => $this->state->isInFlight(),
            'needs_attention' => $this->state->needsAttention(),

            /*
             * The registrar's refusal, already redacted where it was stored.
             * A customer whose registration was refused needs to see why —
             * most refusals are about the name itself, and they can act on it.
             */
            'failure_message' => $this->failure_message,

            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
