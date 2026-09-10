<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Activity\Application\DTOs\ActivityItem;

/**
 * One activity row, as it leaves the API.
 *
 * The list of what is *not* here is the point, and each omission is a field
 * that exists on a source row this is projected from:
 *
 *  - `actor_user_id`. The name is published; the id is an internal join. A
 *    customer reading their history needs to know Ahmed did it, not which row
 *    Ahmed is.
 *  - Any `metadata` map. The sources carry `payload`, `result`, `context`,
 *    `impact` and `preserved` — provider requests, provider responses, operator
 *    reasons and toolkit descriptions. An open map is how one of those reaches
 *    a screen later; there is no map, so it cannot.
 *  - Any failure text. `last_error`, `failure_message` and `failure_reason` are
 *    all provider sentences. The state is a bounded enum instead, and the
 *    detail lives on the resource's own page where there is room to explain it.
 *  - The provider, node, datastore, BMC protocol, remote job id and provider
 *    reference. None is a property of what the customer bought.
 *
 * `state` is the seven-word customer vocabulary, and `retry_advice` is
 * published beside it so a screen never has to derive what may be done next —
 * which is what stops a retry control appearing on an indeterminate row.
 *
 * @mixin ActivityItem
 */
final class ActivityItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ActivityItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'category' => $item->category->value,

            // A translation key. The portal owns the sentence, in the reader's
            // own language.
            'message_code' => $item->messageCode,

            'state' => $item->state->value,
            'is_terminal' => $item->state->isTerminal(),
            'needs_attention' => $item->state->needsAttention(),
            'retry_advice' => $item->state->retryAdvice()->value,

            'actor' => [
                'type' => $item->actorType->value,
                'display_name' => $item->actorName,
            ],

            /*
             * Null where the row is not about something the portal has a page
             * for. Published as a handle rather than a path for the same
             * reason Wave 3 did: the client turns `{kind, id}` into an address
             * through one function, so there is one resource routing map.
             */
            'resource' => $item->resourceKind === null || $item->resourceId === null ? null : [
                'kind' => $item->resourceKind,
                'id' => $item->resourceId,
                'identity' => $item->resourceIdentity,
            ],

            'reference' => $item->reference,
        ];
    }
}
