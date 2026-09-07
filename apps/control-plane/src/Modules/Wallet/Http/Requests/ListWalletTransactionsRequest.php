<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Http\Requests\Concerns\BoundsPageSize;

/**
 * Filtering and paging for the ledger.
 *
 * Note what is not here: no customer id, no account id, and above all no
 * wallet id. Which wallets are read is decided by the acting-customer
 * middleware; a `wallet_id` parameter would be a request to read somebody
 * else's ledger, and the only safe way to treat one is not to accept it. The
 * `currency` filter selects between the acting customer's *own* wallets and
 * can name nothing outside them, which is why it is a currency code and not an
 * id.
 */
final class ListWalletTransactionsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here; see BoundsPageSize.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'kind' => ['sometimes', 'nullable', new Enum(WalletTransactionKind::class)],
            // An ISO-4217 code, refused rather than clamped: "USDD" is a typo,
            // and answering it with an empty page would let a client ship a
            // currency filter that silently shows nothing.
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    public function kind(): ?WalletTransactionKind
    {
        $kind = $this->validated()['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? WalletTransactionKind::from($kind) : null;
    }

    /**
     * The currency whose wallet to read, upper-cased to match how wallets are
     * stored — a filter of "kwd" that matched no rows would look like an empty
     * ledger rather than like a case mistake.
     */
    public function currency(): ?string
    {
        $currency = $this->validated()['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }
}
