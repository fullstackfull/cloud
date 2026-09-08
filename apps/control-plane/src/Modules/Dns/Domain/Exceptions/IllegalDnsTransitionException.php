<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A state change nothing in the product is allowed to make.
 *
 * Thrown rather than logged. Every one of these is a bug in this module, and a
 * bug that writes "active" over a record nobody published is one the platform
 * must not survive quietly.
 */
final class IllegalDnsTransitionException extends DomainException
{
    public static function between(string $id, DnsState $from, DnsState $to): self
    {
        $exception = new self(sprintf('%s cannot go from %s to %s.', $id, $from->value, $to->value));

        return $exception->withContext(['id' => $id, 'from' => $from->value, 'to' => $to->value]);
    }

    public function errorCode(): string
    {
        return 'dns.illegal_transition';
    }

    /**
     * A 500, and it should be.
     *
     * Every one of these is a bug in this module rather than something a
     * customer did, and answering 422 would hand them a message to act on
     * about a state machine they cannot see.
     */
    public function httpStatus(): int
    {
        return 500;
    }
}
