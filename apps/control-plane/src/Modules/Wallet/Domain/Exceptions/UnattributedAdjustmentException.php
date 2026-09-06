<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a manual adjustment is posted without naming who made it.
 *
 * Refusing the write is deliberate. Recording the entry with a null actor and
 * hoping the correlation id is enough leaves an auditor with a balance change
 * nobody owns, which is exactly the shape of the finding this rule exists to
 * prevent.
 */
final class UnattributedAdjustmentException extends DomainException
{
    public static function forWallet(string $walletId): self
    {
        $exception = new self(
            'A wallet adjustment must record the user who made it.'
        );

        return $exception->withContext(['wallet_id' => $walletId]);
    }

    public function errorCode(): string
    {
        return 'wallet.unattributed_adjustment';
    }
}
