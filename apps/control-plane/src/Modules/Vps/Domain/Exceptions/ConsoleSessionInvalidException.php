<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A console session was redeemed and the platform would not honour it.
 *
 * Expired, already used, unknown, or presented with the wrong token — all four
 * answer identically and on purpose. A gateway that could tell "this session
 * has already been used" from "this session never existed" would let anyone
 * who can reach it enumerate live sessions, and a console session is a
 * keyboard attached to somebody's root shell.
 */
final class ConsoleSessionInvalidException extends DomainException
{
    public static function make(): self
    {
        return new self('This console session is not valid. Console sessions are single-use and short-lived.');
    }

    public function errorCode(): string
    {
        return 'vps.console_session_invalid';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
