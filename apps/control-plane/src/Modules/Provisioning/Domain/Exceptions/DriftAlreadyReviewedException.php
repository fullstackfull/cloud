<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A verdict that would overwrite somebody else's.
 *
 * A conflict rather than a validation failure, and the distinction is what the
 * operator sees: 422 reads as "you sent something wrong", which is not what
 * happened. Two people worked the same alert channel and one of them was
 * second. The screen needs to say that, and reload.
 */
final class DriftAlreadyReviewedException extends DomainException
{
    public function errorCode(): string
    {
        return 'drift.already_reviewed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function alreadyResolved(string $driftId, ?string $resolvedBy): self
    {
        return (new self('This drift has already been resolved by another operator.'))
            ->withContext(['drift_id' => $driftId, 'resolved_by_user_id' => $resolvedBy]);
    }

    public static function cannotReopen(string $driftId): self
    {
        return (new self(
            'A drift cannot be reopened; a disagreement that is true again is recorded as a new sighting.',
        ))->withContext(['drift_id' => $driftId]);
    }
}
