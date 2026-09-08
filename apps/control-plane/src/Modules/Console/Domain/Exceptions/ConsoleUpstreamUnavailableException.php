<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\Exceptions;

use RuntimeException;

/**
 * The gateway cannot work out where to connect, or the provider would not say.
 *
 * Separate from a refused connection because the two are different operator
 * problems: a refusal means the platform decided no, and this means the
 * platform tried and could not reach the hypervisor.
 */
final class ConsoleUpstreamUnavailableException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('No console upstream could be resolved: %s.', $reason));
    }
}
