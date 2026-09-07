<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The confirmation did not name this machine.
 *
 * A reinstall wipes the disk. The confirmation is therefore the machine's own
 * hostname, typed out, and not a boolean: a boolean is a field a client
 * library sets to true in its constructor and a wrapper defaults for
 * convenience, and the first customer to find that out finds it out by losing
 * a database.
 *
 * The correct hostname is deliberately NOT echoed back in this error. It is
 * the customer's own machine and they can read it from GET /vps/{vm} whenever
 * they like — but putting it in the failure response turns the confirmation
 * into a two-step handshake any client can perform automatically, which is
 * exactly the safety this field exists to provide.
 */
final class ReinstallConfirmationMismatchException extends DomainException
{
    public static function make(): self
    {
        return new self(
            'The confirmation does not match this machine. Reinstalling erases every disk on it, '
            .'so the request must repeat the machine\'s hostname exactly.'
        );
    }

    public function errorCode(): string
    {
        return 'vps.reinstall_confirmation_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
