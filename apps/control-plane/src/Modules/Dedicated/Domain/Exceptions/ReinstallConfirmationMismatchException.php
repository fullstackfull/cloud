<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A reinstall was asked for without naming the machine it would erase.
 *
 * The confirmation is the machine's serial number, and it is a string on
 * purpose. The obvious alternative — `"confirm": true` — is a field every
 * generated client sets in its constructor, every convenience wrapper
 * defaults, and every retry loop resends without a person ever seeing it.
 * Typing a serial off the front of a chassis cannot be done by accident.
 *
 * The serial rather than a hostname, because a dedicated server's hostname is
 * whatever the customer's own operating system currently says it is — which
 * after a bad install is nothing at all — while the serial is the one
 * identifier that is stable across every rebuild and every customer the
 * machine ever has.
 *
 * The expected value is deliberately not in the context. A refusal that echoed
 * the serial back would turn the confirmation into a two-step form the client
 * can fill in for itself, which is exactly the automation this gate exists to
 * prevent.
 */
final class ReinstallConfirmationMismatchException extends DomainException
{
    public static function make(): self
    {
        return new self(
            'The confirmation does not name this server. Send confirm_serial with the serial number of the machine to be erased.'
        );
    }

    public function errorCode(): string
    {
        return 'dedicated.reinstall_confirmation_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
