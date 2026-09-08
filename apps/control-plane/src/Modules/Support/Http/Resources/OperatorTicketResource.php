<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * A ticket as the support team sees it.
 *
 * Three things the customer's own view does not carry, and each is here
 * because an operator cannot do their job without it:
 *
 *  - **The account**, by name and id. An operator works across accounts and
 *    the whole question is whose problem this is.
 *  - **The internal notes**, which is what the `messages` relation loads on
 *    this surface where the customer's loads `customerVisibleMessages`.
 *  - **`first_responded_at` and `reopened_count`**, which are how the team
 *    measures itself. A ticket resolved and reopened four times was never
 *    fixed, and that is worth seeing without reading the thread.
 *
 * @mixin SupportTicket
 */
final class OperatorTicketResource extends JsonResource
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
            'customer_id' => (string) $ticket->customer_id,
            'customer_name' => $ticket->customer?->display_name,
            'service_id' => $ticket->service_id,
            'invoice_id' => $ticket->invoice_id,
            /*
             * Flat rather than a nested object, and deliberately: the id is
             * what the assignment endpoint takes back, and a nested shape
             * would put a bare `name` key in the response that reads as the
             * ticket's own.
             */
            'assigned_to_id' => $ticket->assigned_to_user_id,
            'assigned_to' => $ticket->assignee?->name,
            'opened_by' => $ticket->openedBy?->name,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'last_reply_by' => $ticket->last_reply_by?->value,
            'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
            'reopened_count' => $ticket->reopened_count,
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at->toIso8601String(),
            'messages' => TicketMessageResource::collection(
                $ticket->relationLoaded('messages') ? $ticket->getRelation('messages') : [],
            ),
        ];
    }
}
