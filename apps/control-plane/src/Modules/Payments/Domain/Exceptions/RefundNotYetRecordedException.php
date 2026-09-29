<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A provider reported a refund this platform has no row for under that
 * reference - yet.
 *
 * IssueRefund writes the refund row before it asks the provider, and the
 * provider's reference for the refund only once the answer is back. A
 * provider fast enough to send the refund's event in between would have it
 * acknowledged and lost: the row would then stay pending for ever. So the
 * event is answered with a failure the provider retries (503), the webhook
 * row is left unsettled (Failed, with this message, for an operator), and a
 * later delivery finds the row.
 *
 * The same answer is given for a refund this platform never made (one issued
 * in the provider's own dashboard): the provider retries it until it gives
 * up, each attempt recorded on the webhook row, which is where an operator
 * sees a refund the books do not know about.
 */
final class RefundNotYetRecordedException extends DomainException
{
    public static function forReference(string $provider, string $refundReference): self
    {
        $exception = new self(sprintf(
            'Provider %s reported refund %s, which no refund row carries yet.',
            $provider,
            $refundReference,
        ));

        return $exception->withContext(['provider' => $provider, 'refund_reference' => $refundReference]);
    }

    public function errorCode(): string
    {
        return 'payment.refund_not_yet_recorded';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
