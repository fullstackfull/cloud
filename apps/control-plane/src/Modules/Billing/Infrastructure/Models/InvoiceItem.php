<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Infrastructure\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One line of an invoice, with the tax that was charged on it.
 *
 * The rate and its name are copied onto the line rather than looked up through
 * the tax rule that produced them: rules change, and an invoice has to keep
 * showing the rate the customer was actually charged.
 *
 * `tax_rate` stays a decimal string all the way through — numeric(9,6) in the
 * database, a string in PHP — because the one thing that must not happen to a
 * rate is a trip through a float.
 *
 * @property string $id
 * @property string $invoice_id
 * @property InvoiceItemKind $kind
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $tax_rate
 * @property string|null $tax_name
 * @property Invoice $invoice
 */
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceItemKind::class,
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function unitAmount(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency());
    }

    public function discount(): Money
    {
        return Money::ofMinor($this->discount_minor, $this->currency());
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency());
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency());
    }

    public function taxRate(): TaxRate
    {
        return TaxRate::of($this->tax_rate, $this->tax_name);
    }

    /**
     * A line has no currency of its own: it is denominated in the currency of
     * the invoice it belongs to, and a line that disagreed with its invoice
     * would be a bug with no correct interpretation.
     */
    private function currency(): string
    {
        return $this->invoice->currency;
    }
}
