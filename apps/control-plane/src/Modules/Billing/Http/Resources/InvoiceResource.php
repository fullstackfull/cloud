<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

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
     * Whether this is being serialised as a document rather than as a row in
     * a list. A document carries the billing snapshot; a row does not.
     */
    public function __construct(Invoice $resource, private readonly bool $showsDocument = false)
    {
        parent::__construct($resource);
    }

    /**
     * The document is a document: one invoice, one screen, one printable page.
     */
    public static function document(Invoice $invoice): self
    {
        return new self($invoice, showsDocument: true);
    }

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

            'subtotal' => $this->moneyOfMinor($this->subtotal_minor, $this->currency),
            'discount' => $this->moneyOfMinor($this->discount_minor, $this->currency),
            'tax' => $this->moneyOfMinor($this->tax_minor, $this->currency),
            'total' => $this->moneyOfMinor($this->total_minor, $this->currency),
            'amount_paid' => $this->moneyOfMinor($this->amount_paid_minor, $this->currency),
            'amount_refunded' => $this->moneyOfMinor($this->amount_refunded_minor, $this->currency),
            'amount_due' => $this->moneyOfMinor($this->amount_due_minor, $this->currency),

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

            /*
             * The billing profile as it was when the invoice was issued.
             *
             * Not the customer's current profile, and this is the whole point:
             * an issued invoice is a document about a moment. Rendering it
             * against today's address would rewrite history every time
             * somebody moved office, and would make a printed invoice
             * disagree with the one the customer printed last month.
             *
             * Published only where a document is being shown — the detail
             * screen and the printable view — because a list of twelve
             * invoices has no use for twelve copies of an address.
             */
            'billing_snapshot' => $this->when(
                $this->showsDocument,
                fn (): array => $this->documentSnapshot(),
            ),

            /*
             * How this invoice was paid, or failed to be.
             *
             * Two separate lists, because wallet credit and a card charge are
             * two different things and an invoice settled from both must show
             * both. Neither list is arithmetic the browser does: the amounts
             * are the server's, and amount_paid above is the invoice's own
             * record rather than a sum of these rows.
             */
            'payments' => $this->whenLoaded(
                'transactions',
                fn (): array => $this->transactions
                    ->map(fn (Transaction $payment): InvoicePaymentResource => new InvoicePaymentResource($payment))
                    ->values()
                    ->all(),
            ),

            'wallet_credits' => $this->whenLoaded(
                'walletCredits',
                fn (): array => $this->walletCredits
                    ->map(fn (WalletTransaction $entry): InvoiceWalletCreditResource => new InvoiceWalletCreditResource($entry))
                    ->values()
                    ->all(),
            ),

            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The snapshot, with nothing added and nothing filled in.
     *
     * An invoice issued before a field existed simply does not carry it, and
     * the screen omits the line rather than showing an empty one. What must
     * never happen here is a fallback to the customer's current profile: that
     * would silently mutate an issued document, which is the one thing an
     * invoice may not do.
     *
     * @return array<string, mixed>
     */
    private function documentSnapshot(): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $this->resource->billing_snapshot;

        // The customer id is in the snapshot for internal traceability and
        // adds nothing to a document the customer is reading.
        unset($snapshot['customer_id']);

        return $snapshot;
    }
}
