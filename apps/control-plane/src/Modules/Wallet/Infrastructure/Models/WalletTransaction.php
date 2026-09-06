<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;

/**
 * One append-only line of a wallet ledger.
 *
 * `amount_minor` is signed: positive credits the customer, negative debits.
 * A single signed column rather than a direction flag plus a magnitude means
 * the balance is a SUM() that cannot disagree with itself, which is what makes
 * the ledger — not the wallet row — the source of truth.
 *
 * Entries are never updated or deleted. Correcting a mistake means posting a
 * compensating `adjustment` that names its author.
 *
 * @property string $id
 * @property string $wallet_id
 * @property int $amount_minor
 * @property int $balance_after_minor
 * @property WalletTransactionKind $kind
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property Wallet $wallet
 */
class WalletTransaction extends Model
{
    use HasUlids;

    /**
     * The ledger is append-only, so the table carries no updated_at to keep
     * a row from looking as though it could have been edited after the fact.
     */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * The metadata key under which an idempotency key is stored.
     *
     * The table has no dedicated column for it, so the key lives in the
     * metadata document. It is written after redaction and never taken from
     * caller-supplied metadata, so a caller cannot forge or clobber it.
     */
    public const string IDEMPOTENCY_METADATA_KEY = 'idempotency_key';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'kind' => WalletTransactionKind::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The signed amount. An entry carries no currency of its own — it can only
     * ever be in its wallet's currency — so the wallet relation must be
     * loaded; WalletLedger stamps it on every entry it returns.
     */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->wallet->currency);
    }

    public function balanceAfter(): Money
    {
        return Money::ofMinor($this->balance_after_minor, $this->wallet->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_minor > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount_minor < 0;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->metadata[self::IDEMPOTENCY_METADATA_KEY] ?? null;

        return is_string($key) ? $key : null;
    }
}
