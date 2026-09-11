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
             * The registrar's own refusal sentence is not published.
             *
             * It was, with a comment saying a customer whose registration was
             * refused needs to see why — which is true, and which this field
             * never delivered: no screen rendered it, and what it held was the
             * registrar's English prose. `state` and `needs_attention` say
             * that something stopped and that a person has to look at it, and
             * the contextual support link carries the operation. A bounded
             * registrar-reason vocabulary, translated like every other reason
             * the portal shows, is the way to answer "why" properly.
             */

            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
