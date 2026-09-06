<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

use BackedEnum;

/**
 * Raised when code attempts a state transition the state machine forbids.
 *
 * Forbidden transitions throw rather than silently no-op, so an ordering or
 * provisioning bug surfaces immediately instead of leaving a service stuck in
 * an inconsistent state.
 */
final class IllegalStateTransitionException extends DomainException
{
    public static function between(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        $exception = new self(sprintf(
            '%s cannot transition from "%s" to "%s".',
            $subject,
            (string) $from->value,
            (string) $to->value,
        ));

        return $exception->withContext([
            'subject' => $subject,
            'from' => (string) $from->value,
            'to' => (string) $to->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'state.illegal_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
