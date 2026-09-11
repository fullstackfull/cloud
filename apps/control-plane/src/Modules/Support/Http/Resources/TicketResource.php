<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * A ticket as its own account may see it.
 *
 * The assignee is a name, not a user id: which operator is handling a ticket
 * is worth knowing and their id is not, and an id on a customer surface is a
 * shape to probe with.
 *
 * `first_responded_at` is absent. It is how the support team is measured, not
 * something the customer asked about, and publishing it invites an argument
 * about a number the customer cannot check.
 *
 * @mixin SupportTicket
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->resource;

        return [
            'id' => (string) $ticket->getKey(),
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category->value,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'service_id' => $ticket->service_id,
            'invoice_id' => $ticket->invoice_id,
            'opened_by' => $ticket->openedBy?->name,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'last_reply_by' => $ticket->last_reply_by?->value,
            'awaiting_customer' => $ticket->status->value === 'waiting_for_customer',
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at->toIso8601String(),
            'messages' => TicketMessageResource::collection(
                $ticket->relationLoaded('customerVisibleMessages')
                    ? $ticket->getRelation('customerVisibleMessages')
                    : [],
            ),
        ];
    }
}
