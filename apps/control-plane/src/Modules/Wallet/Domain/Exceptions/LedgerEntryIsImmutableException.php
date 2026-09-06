<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when something tries to change or remove a ledger entry.
 *
 * The ledger is the source of truth for a balance the platform will defend in
 * front of a customer, so an entry that can be edited after the fact is worth
 * nothing as evidence. Mistakes are corrected by posting a compensating
 * `adjustment` that names its author, never by rewriting history.
 *
 * This is a programming error rather than user input, hence the 500: no
 * request should ever reach a code path that edits a posted entry.
 */
final class LedgerEntryIsImmutableException extends DomainException
{
    public static function forEntry(string $entryId, string $operation): self
    {
        $exception = new self(sprintf(
            'Wallet ledger entries are append-only; entry %s cannot be %s.',
            $entryId,
            $operation,
        ));

        return $exception->withContext([
            'entry_id' => $entryId,
            'operation' => $operation,
        ]);
    }

    public function errorCode(): string
    {
        return 'wallet.ledger_entry_immutable';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
