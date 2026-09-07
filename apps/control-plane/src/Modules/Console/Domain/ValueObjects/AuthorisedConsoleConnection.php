<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\ValueObjects;

use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * A connection that has proved everything it needs to, and where it may go.
 *
 * Exists so the socket loop cannot accidentally connect anywhere except where
 * the authorisation decided: the loop receives this object and never sees the
 * session id, the token, or the machine id the client claimed.
 *
 * @immutable
 */
final readonly class AuthorisedConsoleConnection
{
    public function __construct(
        public ConsoleSession $session,
        public ConsoleUpstream $upstream,
        public string $virtualMachineId,
    ) {}
}
