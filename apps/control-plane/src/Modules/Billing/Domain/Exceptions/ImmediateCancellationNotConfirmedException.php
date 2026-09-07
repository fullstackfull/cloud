<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An immediate cancellation arrived without proof that a person asked for it.
 *
 * Cancelling at the end of the paid period is reversible — the row stays
 * active, the service keeps running, and CancelSubscription::revoke() puts it
 * back. Cancelling *immediately* is not: the state machine has no edge out of
 * cancelled, the service stops the same second, and the remainder of a period
 * the customer already paid for is not returned. The customer's way back is to
 * buy again, which is a new order, a new subscription and a new provisioning
 * run.
 *
 * So the destructive form is asked for by naming the subscription, exactly as
 * a reinstall is asked for by naming the machine — the same rule the VPS and
 * dedicated reinstall surfaces apply with confirm_hostname and confirm_serial.
 * A boolean is not a confirmation: it is a field a generated client sets in
 * its constructor, a convenience wrapper defaults, and a retry loop resends
 * without a person ever seeing it.
 *
 * The id is deliberately not echoed back. It is the customer's own
 * subscription and GET /subscriptions/{subscription} will tell them whenever
 * they ask — but returning it here would turn the confirmation into a two-step
 * handshake any client can perform on its own, which is the whole of the
 * safety this field provides.
 */
final class ImmediateCancellationNotConfirmedException extends DomainException
{
    public static function make(): self
    {
        return new self(
            'The confirmation does not name this subscription. Cancelling immediately stops the service '
            .'now and does not return the remainder of the period already paid for, so the request must '
            .'repeat the subscription\'s own id.'
        );
    }

    public function errorCode(): string
    {
        return 'subscription.immediate_cancellation_not_confirmed';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
