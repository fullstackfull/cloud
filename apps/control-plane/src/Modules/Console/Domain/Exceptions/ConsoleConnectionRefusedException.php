<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\Exceptions;

use RuntimeException;

/**
 * The gateway will not open this console.
 *
 * One exception with one message for every way a connection can be refused —
 * unknown permit, spent permit, expired permit, wrong customer, wrong machine,
 * rate limited. The gateway is reachable by anyone who can open a socket to
 * it, and a refusal that explained itself would let them tell a live session
 * id from a dead one, or confirm that a machine id exists.
 *
 * The *reason* is carried separately so it can be audited and counted without
 * ever being sent to the peer.
 */
final class ConsoleConnectionRefusedException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('This console session cannot be opened.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
