<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Billing\Http\Resources\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;

/**
 * What a customer may see of their own invoice.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - customer_id: the caller already knows which account they are acting for.
 *    The id buys them nothing but a shape to probe with.
 *  - billing_snapshot: the frozen copy of the account's billing details. It
 *    exists so the *document* cannot be rewritten by a later address change,
 *    and it is served to the customer by the profile endpoints. Repeating a
 *    tax id and a postal address on every row of an invoice list widens where
 *    that data can leak from without telling the customer anything new.
 *  - notes: written by whoever issued the invoice, not by the customer.
 *    Nothing on the customer surface puts text in it, so it is an operator's
 *    field until something says otherwise, and an operator's note is not part
 *    of the document the customer bought.
 *
 * `amount_due` is read from the generated column rather than recomputed from
 * total − paid + refunded. A second implementation of that subtraction is
 * exactly the drift the generated column exists to prevent, and a resource is
 * the last place it should appear.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Allocated from the database sequence when the invoice left
            // draft. Read here, never generated.
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,

            'subtotal' => $this->money($this->subtotal_minor, $this->currency),
            'discount' => $this->money($this->discount_minor, $this->currency),
            'tax' => $this->money($this->tax_minor, $this->currency),
            'total' => $this->money($this->total_minor, $this->currency),
            'amount_paid' => $this->money($this->amount_paid_minor, $this->currency),
            'amount_refunded' => $this->money($this->amount_refunded_minor, $this->currency),
            'amount_due' => $this->money($this->amount_due_minor, $this->currency),

            // Asked of the enum that decides, so a client's "can I pay this?"
            // cannot drift away from what the platform would actually accept.
            'is_payable' => $this->status->isCollectible(),
            'is_settled' => $this->status->isSettled(),

            // The customer's own order and subscription, so a client can link
            // the document back to what it bills for. Both are ids within the
            // acting account; neither is another tenant's.
            'order_id' => $this->order_id,
            'subscription_id' => $this->subscription_id,

            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->items
                    ->map(fn (InvoiceItem $item): InvoiceItemResource => new InvoiceItemResource($item, $this->currency))
                    ->all(),
            ),

            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
