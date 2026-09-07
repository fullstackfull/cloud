<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * One line of a customer's statement.
 *
 * The entry's own wallet must be loaded before this runs: an entry carries no
 * currency of its own, only its wallet's, so serialising one without its
 * wallet would be a lazy load per row at best and a currency guess at worst.
 * The ledger query eager-loads it.
 *
 * ---------------------------------------------------------------------------
 * Absent on purpose
 * ---------------------------------------------------------------------------
 *
 *  - metadata: free-form, and frequently a slice of a provider response that
 *    reached storage through the redactor. It also carries the reserved
 *    `idempotency_key` the ledger's replay check reads. Publishing that key
 *    hands a caller the value another system uses to recognise its own retry;
 *    publishing the rest widens where a payment payload can leak from, on
 *    every row of a paginated list, for no question a customer was asking.
 *  - created_by_user_id: the operator who posted an adjustment. That is a
 *    staff identity, and the entry's `description` is the account's
 *    explanation of what happened — the platform owes the customer the reason,
 *    not the name of the employee.
 *  - balance_after_minor as a bare integer: it goes out as Money, like every
 *    other amount.
 *
 * `invoice_id` and `transaction_id` are kept: both are the customer's own,
 * both are already readable at /invoices/{id} and /payments/{id}, and without
 * them a statement line saying "Applied to invoice LYN-000012" cannot be
 * linked to the document it names.
 *
 * @mixin WalletTransaction
 */
final class WalletTransactionResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The customer's own wallet, so a multi-currency account can group
            // its statement by the balance each line moved.
            'wallet_id' => $this->wallet_id,

            'kind' => $this->kind->value,

            // Signed, exactly as stored: positive credited the customer,
            // negative debited them. A direction flag alongside a magnitude
            // would be a second representation of the same fact, and the two
            // can disagree; this one is derived from the sign it is next to.
            'amount' => $this->money($this->amount()),
            'direction' => $this->isCredit() ? 'credit' : 'debit',

            // Stamped by the ledger when the entry was written, not recomputed
            // here. It is what makes the statement auditable without replaying
            // every row above it.
            'balance_after' => $this->money($this->balanceAfter()),

            // Written for the person whose balance moved; the ledger refuses
            // an entry without one.
            'description' => $this->description,

            'invoice_id' => $this->invoice_id,
            'transaction_id' => $this->transaction_id,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
