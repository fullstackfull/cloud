<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;

/**
 * Raised when an idempotency key already names an entry that is not the one
 * being posted.
 *
 * A replay is only safe to answer with the original entry when the original
 * entry is what the caller is asking for. If the key is already attached to a
 * different kind or a different amount then the two calls are not the same
 * call, and returning the existing entry would report a top-up as complete
 * that was never made — or, worse, report an invoice as settled while the
 * debit that should have paid it was silently dropped.
 *
 * Refusing loudly turns a key collision into an operator's problem instead of
 * a customer's missing money.
 */
final class IdempotencyKeyConflictException extends DomainException
{
    public static function forEntry(
        string $walletId,
        string $idempotencyKey,
        string $existingEntryId,
        WalletTransactionKind $requestedKind,
        int $requestedAmountMinor,
    ): self {
        $exception = new self(sprintf(
            'Idempotency key "%s" is already held by wallet entry %s, which is not the entry being posted.',
            $idempotencyKey,
            $existingEntryId,
        ));

        return $exception->withContext([
            'wallet_id' => $walletId,
            'idempotency_key' => $idempotencyKey,
            'existing_entry_id' => $existingEntryId,
            'requested_kind' => $requestedKind->value,
            'requested_amount_minor' => $requestedAmountMinor,
        ]);
    }

    public function errorCode(): string
    {
        return 'wallet.idempotency_key_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
