<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A console session was asked for and could not be issued.
 *
 * Distinct from the power refusals because the remedy is different: a console
 * is how a customer rescues a machine that will not boot, so it is available
 * for a machine that is stopped — it is only unavailable when there is no
 * machine at the hypervisor to attach to at all.
 */
final class ConsoleSessionUnavailableException extends DomainException
{
    public static function notRunnable(): self
    {
        return new self(
            'A console cannot be opened for this machine: the hypervisor has no confirmed machine to attach to.'
        );
    }

    public function errorCode(): string
    {
        return 'vps.console_unavailable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
