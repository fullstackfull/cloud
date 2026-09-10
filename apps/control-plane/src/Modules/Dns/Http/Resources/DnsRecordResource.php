<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Responses\CustomerFailureReason;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;

/**
 * One record, as the account holding its zone may see it.
 *
 * The state is published rather than folded into a boolean, because the
 * difference between `failed` and `indeterminate` is the difference between
 * "fix this and try again" and "do not touch this yet" — and a customer who
 * cannot tell them apart will republish a record that may already be live.
 *
 * @mixin DnsRecord
 */
final class DnsRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zone_id' => $this->dns_zone_id,
            'type' => $this->type->value,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'priority' => $this->priority,

            // CAA's three fields, flattened. A nested object here would be one
            // more shape for every client to learn, and the presentation form
            // in `content` already says the same thing for reading.
            'caa_flags' => $this->data['flags'] ?? null,
            'caa_tag' => $this->data['tag'] ?? null,
            'caa_value' => $this->data['value'] ?? null,

            'state' => $this->state->value,
            'is_live' => $this->state->isLive(),
            'is_being_deleted' => $this->state->isBeingDeleted(),
            'needs_attention' => $this->state->needsAttention(),

            'failure_reason' => CustomerFailureReason::describe($this->failure_reason, 'dns.record_operation_failed'),
            'last_published_at' => $this->last_published_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
