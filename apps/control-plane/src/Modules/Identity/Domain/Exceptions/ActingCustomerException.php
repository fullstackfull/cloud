<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The request could not be attributed to exactly one customer account.
 *
 * Every customer-scoped endpoint acts on behalf of one account, and which one
 * is never taken from the request body. This is what is thrown when the
 * question cannot be answered.
 */
final class ActingCustomerException extends DomainException
{
    /** Never `\$code`: Exception already declares an untyped one, and typing it is fatal at class load. */
    private string $errorCode;

    public static function noAccount(): self
    {
        $exception = new self(
            'This login is not a member of any customer account.'
        );
        $exception->errorCode = 'tenancy.no_account';

        return $exception;
    }

    public static function ambiguous(int $count): self
    {
        $exception = new self(
            'This login belongs to more than one customer account, so the account to act for must be named.'
        );
        $exception->errorCode = 'tenancy.account_required';

        return $exception->withContext([
            'account_count' => $count,
            'header' => 'X-Lynomia-Customer',
        ]);
    }

    public static function notAMember(): self
    {
        /*
         * Deliberately the same wording and the same code as an account that
         * does not exist at all. Distinguishing them would let a caller
         * enumerate customer ids by watching which ones answer differently.
         */
        $exception = new self(
            'That customer account does not exist, or this login is not a member of it.'
        );
        $exception->errorCode = 'tenancy.account_unavailable';

        return $exception;
    }

    public static function outsideTokenScope(): self
    {
        $exception = new self(
            'This API token is bound to a different customer account.'
        );
        $exception->errorCode = 'tenancy.outside_token_scope';

        return $exception;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
