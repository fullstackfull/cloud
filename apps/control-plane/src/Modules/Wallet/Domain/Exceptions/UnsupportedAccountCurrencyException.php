<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * Raised when a balance would have to be expressed in a currency the platform
 * cannot express money in at all.
 *
 * This is not a bad request — nothing the caller sent is wrong. It is an
 * account carrying a `currency` that is three letters but not an ISO-4217
 * code, which the registration endpoint accepts today (`['string', 'size:3',
 * 'alpha']` admits "ZZZ") and stores verbatim. Every amount in the platform
 * goes through Money, Money goes through Brick, and Brick refuses an unknown
 * code — so such an account cannot be given a balance, an invoice total, or a
 * price.
 *
 * Before this existed the refusal escaped as Brick's own RuntimeException and
 * the customer got `server.error` with no code to branch on and no name in the
 * logs to alert on. A wallet that cannot be expressed is a data defect, and
 * this module's whole stance — see GetCustomerWalletBalances on why the ledger
 * is not silently re-derived — is that a defect is reported rather than
 * papered over. Answering with an empty balance list would tell a customer
 * they hold nothing, which is a different claim from "the platform cannot say".
 *
 * It stays a 5xx because it is the platform's data that is wrong, not the
 * request: no change the caller makes to their query will fix it.
 */
final class UnsupportedAccountCurrencyException extends DomainException
{
    public static function forCurrency(string $currency, ?Throwable $previous = null): self
    {
        $exception = new self(
            sprintf(
                'The account currency "%s" is not a currency a balance can be expressed in.',
                strtoupper($currency),
            ),
            previous: $previous,
        );

        // The customer's own account currency, and nothing else. It is the one
        // fact that makes the failure diagnosable, and it is already theirs.
        return $exception->withContext(['currency' => strtoupper($currency)]);
    }

    public function errorCode(): string
    {
        return 'wallet.unsupported_currency';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
