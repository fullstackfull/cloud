<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A state change nothing in the product is allowed to make.
 *
 * Every one of these is a bug in this module, and the expensive ones are
 * specific: writing `active` over a domain that was deleted claims a name the
 * platform does not hold, and writing it over a failed registration claims one
 * a registrar refused.
 */
final class IllegalDomainTransitionException extends DomainException
{
    public static function between(string $id, DomainState $from, DomainState $to): self
    {
        $exception = new self(sprintf('%s cannot go from %s to %s.', $id, $from->value, $to->value));

        return $exception->withContext(['id' => $id, 'from' => $from->value, 'to' => $to->value]);
    }

    public function errorCode(): string
    {
        return 'domain.illegal_transition';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
