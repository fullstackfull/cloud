<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

/**
 * The request carried no usable `Idempotency-Key` header.
 *
 * Raised instead of a generic validation failure, and the distinction is the
 * whole reason for the class. A validation failure says "a field on the form
 * is wrong", and a client renders it as "please correct the highlighted
 * fields" — but the idempotency key is not a field a person fills in. It is a
 * transport requirement between the client and the API, so when it is missing
 * the client is broken, not the customer, and the error has to say which
 * header is expected rather than point at a box that does not exist.
 *
 * The customer portal sends the header on every guarded mutation; the only
 * caller that will ever see this is an integration that forgot it, which is
 * why the message is written for a developer.
 */
final class IdempotencyKeyRejectedException extends DomainException
{
    public const string HEADER = 'Idempotency-Key';

    public static function because(string $reason): self
    {
        return (new self($reason))->withContext([
            'header' => self::HEADER,
            'field' => 'idempotency_key',
        ]);
    }

    public function errorCode(): string
    {
        return 'request.idempotency_key_rejected';
    }
}
