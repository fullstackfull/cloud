<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a cancellation is asked of a subscription that has already ended.
 *
 * Cancelled and terminated are the two terminal states, and neither has a way
 * back — resuming a subscription whose service has been torn down would bill
 * for something that no longer exists. So there is nothing left to cancel, and
 * both forms of the request are refused rather than half-applied:
 *
 *  - An *immediate* cancellation would ask the state machine for a transition
 *    out of a terminal state, which terminated forbids outright.
 *  - A *scheduled* cancellation is worse, because it would quietly succeed:
 *    it only writes `cancel_at` and `auto_renew`, so it would stamp a future
 *    cancellation date onto a subscription that ended months ago and hand the
 *    customer back a row claiming it is still winding down.
 */
final class SubscriptionAlreadyEndedException extends DomainException
{
    public static function forStatus(string $subscriptionId, SubscriptionStatus $status): self
    {
        $exception = new self(sprintf(
            'Subscription %s is %s and has already ended; there is nothing to cancel.',
            $subscriptionId,
            $status->value,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'status' => $status->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'subscription.already_ended';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
