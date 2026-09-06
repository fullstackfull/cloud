<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a plan change is attempted on a subscription that is not running.
 *
 * Proration prices the remainder of a period the customer is currently being
 * served for. A suspended, cancelled or terminated subscription is not serving
 * anything, so there is no remainder to credit — moving one of those onto
 * another plan is a new purchase.
 */
final class SubscriptionNotChangeableException extends DomainException
{
    public static function forStatus(string $subscriptionId, SubscriptionStatus $status): self
    {
        $exception = new self(sprintf(
            'Subscription %s is %s and cannot change plan.',
            $subscriptionId,
            $status->value,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'status' => $status->value,
        ]);
    }

    /**
     * The subscription's recurring amount is not a whole multiple of its
     * current plan's unit price, so the number of units it is paying for
     * cannot be established — usually a grandfathered price that has since
     * been changed in the catalogue.
     */
    public static function becauseUnitCountIsUnknown(string $subscriptionId): self
    {
        $exception = new self(sprintf(
            'Subscription %s does not bill a whole multiple of its plan price, so the number of units to move onto the new plan must be supplied explicitly.',
            $subscriptionId,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'reason' => 'unit_count_unknown',
        ]);
    }

    public function errorCode(): string
    {
        return 'subscription.not_changeable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
