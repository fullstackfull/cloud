<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A reservation was committed or extended after it stopped being live.
 *
 * The two ways a reservation dies are worth distinguishing to whoever reads
 * the alert: it was released (the reaper found its job dead, or an operator
 * reclaimed it) or its window elapsed. Either way the address may already
 * belong to somebody else, so committing it would hand two services the same
 * address — the exact collision the locking exists to prevent.
 */
final class ReservationExpiredException extends DomainException
{
    public static function released(string $reservationId, ?string $reason): self
    {
        $exception = new self(sprintf(
            'Reservation %s was already released (%s) and can no longer be committed.',
            $reservationId,
            $reason ?? 'no reason recorded',
        ));

        return $exception->withContext([
            'reservation_id' => $reservationId,
            'released_reason' => $reason,
        ]);
    }

    public static function elapsed(string $reservationId, string $expiredAt): self
    {
        $exception = new self(sprintf(
            'Reservation %s expired at %s and can no longer be committed.',
            $reservationId,
            $expiredAt,
        ));

        return $exception->withContext([
            'reservation_id' => $reservationId,
            'expires_at' => $expiredAt,
        ]);
    }

    public function errorCode(): string
    {
        return 'ipam.reservation_expired';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
