<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Infrastructure\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Casts\DatabaseGeneratedInteger;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A financial document: what a customer was billed, and how it was settled.
 *
 * Two properties of this row are worth stating outright, because most of the
 * invoicing code exists to preserve them.
 *
 * **The document is frozen once issued.** `number`, `issued_at`, `due_at` and
 * `billing_snapshot` are written when the invoice leaves draft and are never
 * rewritten. The snapshot in particular is a copy, not a join: an invoice that
 * has been sent — and possibly filed for tax — must keep saying what it said
 * when it was sent, whatever the customer later edits on their account.
 *
 * **`amount_due_minor` is the database's opinion, not ours.** It is a stored
 * generated column, so it cannot drift from total − paid + refunded no matter
 * which code path moved the parts. The model refuses to assign it — see
 * DatabaseGeneratedInteger — which also means the in-memory value is stale
 * after a write until the row is refreshed.
 *
 * The status column is never assigned directly: every change goes through
 * TransitionInvoice and its state machine.
 *
 * @property string $id
 * @property string $customer_id
 * @property string|null $order_id
 * @property string|null $subscription_id
 * @property string $number
 * @property InvoiceStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property int $amount_refunded_minor
 * @property int $amount_due_minor
 * @property array<string, mixed> $billing_snapshot
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            // Read-only: PostgreSQL derives this one.
            'amount_due_minor' => DatabaseGeneratedInteger::class,
            'billing_snapshot' => 'array',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The lines, each already knowing which invoice it belongs to.
     *
     * A line is denominated in its invoice's currency, so it cannot answer
     * what it is worth without one; chaperoning the relation hands every line
     * the invoice it came from instead of leaving it to lazy-load one.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->chaperone();
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotal_minor, $this->currency);
    }

    public function discount(): Money
    {
        return Money::ofMinor($this->discount_minor, $this->currency);
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function amountPaid(): Money
    {
        return Money::ofMinor($this->amount_paid_minor, $this->currency);
    }

    public function amountRefunded(): Money
    {
        return Money::ofMinor($this->amount_refunded_minor, $this->currency);
    }

    /**
     * What is still owed, as the database derives it.
     *
     * A model whose parts have just been changed in memory has not yet been
     * told the new figure, so this reads the column and callers refresh after
     * a write rather than recomputing it here — a second implementation of the
     * subtraction is exactly the drift the generated column exists to prevent.
     */
    public function amountDue(): Money
    {
        return Money::ofMinor($this->amount_due_minor, $this->currency);
    }

    /**
     * What could still be refunded: money actually received, less what has
     * already been sent back.
     */
    public function refundableAmount(): Money
    {
        return $this->amountPaid()->minus($this->amountRefunded());
    }

    public function isFullyPaid(): bool
    {
        return $this->amountPaid()->isGreaterThanOrEqualTo($this->total());
    }
}
