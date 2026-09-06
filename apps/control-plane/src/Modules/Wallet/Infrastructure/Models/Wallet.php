<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Infrastructure\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A customer's stored-value balance in one currency.
 *
 * There is exactly one wallet per (customer, currency) — enforced by a unique
 * constraint — because a balance that could be split across two rows for the
 * same currency has no single answer to "what can this customer spend".
 *
 * `balance_minor` is a cache. The ledger in wallet_transactions is the source
 * of truth, and WalletLedger::reconcile() exists precisely because a cache can
 * drift. Nothing outside WalletLedger may assign this column.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $currency
 * @property int $balance_minor
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
        ];
    }

    /**
     * ISO-4217 codes are upper case, and Money normalises to upper case, so a
     * wallet stored in another case would fail every currency check against
     * its own currency. Normalising on the way in keeps that row from existing.
     *
     * @return Attribute<string, string>
     */
    protected function currency(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => strtoupper($value),
        );
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * The cached balance. Cheap, and correct as long as the invariants in
     * WalletLedger hold; use WalletLedger::recomputeBalance() when the answer
     * must be derived from the ledger rather than trusted.
     */
    public function balance(): Money
    {
        return Money::ofMinor($this->balance_minor, $this->currency);
    }
}
