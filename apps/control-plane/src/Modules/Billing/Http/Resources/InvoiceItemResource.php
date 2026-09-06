<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Billing\Http\Resources\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;

/**
 * One line of an invoice, with the tax that was actually charged on it.
 *
 * The currency is passed in from the invoice rather than read back through
 * `$item->invoice->currency`. A line has no currency of its own — it is
 * denominated in its invoice's — and reaching through the relation to find out
 * would load the parent again for a document that is already holding it.
 *
 * `subscription_id` is on the row and is deliberately not here. It is the
 * subscription the *renewal worker* attributed the line to, and it is not a
 * foreign key the database constrains; the invoice's own `subscription_id`
 * already tells the customer what this document is for.
 *
 * @mixin InvoiceItem
 */
final class InvoiceItemResource extends JsonResource
{
    use SerialisesMoney;

    public function __construct(InvoiceItem $resource, private readonly string $currency)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'description' => $this->description,
            'quantity' => $this->quantity,

            'unit_amount' => $this->money($this->unit_amount_minor, $this->currency),
            'discount' => $this->money($this->discount_minor, $this->currency),
            'tax' => $this->money($this->tax_minor, $this->currency),
            'total' => $this->money($this->total_minor, $this->currency),

            // A decimal string, never a float: 0.15 as a double is not 0.15,
            // and a client that re-derives tax from it would disagree with the
            // figures above by a fils on a large invoice.
            'tax_rate' => (string) $this->tax_rate,
            'tax_name' => $this->tax_name,

            // The service window this charge covers, for recurring and
            // prorated lines. Null on one-off lines such as setup fees.
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
        ];
    }
}
