<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a mid-cycle plan change would also change the billing period.
 *
 * Proration credits the unused remainder of the current period and charges the
 * new plan for that same remainder. If the two plans are priced over
 * different-length periods there is no shared remainder to measure against,
 * and any answer would be an invented exchange rate between a month and a
 * year. Moving between billing periods is therefore a new subscription taking
 * effect at the next renewal, not a proration.
 */
final class IncompatibleBillingPeriodException extends DomainException
{
    public static function between(string $subscriptionId, BillingPeriod $current, BillingPeriod $requested): self
    {
        $exception = new self(sprintf(
            'Subscription %s is billed %s and cannot be prorated onto a %s plan.',
            $subscriptionId,
            $current->value,
            $requested->value,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'current_period' => $current->value,
            'requested_period' => $requested->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'subscription.incompatible_billing_period';
    }
}
