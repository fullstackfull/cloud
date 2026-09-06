<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when the renewal worker is handed a subscription it must not bill.
 *
 * Renewal throws rather than returning quietly here, because every one of
 * these cases means the caller's selection is wrong: the due-for-renewal scope
 * already excludes cancelled, terminated and suspended subscriptions, those
 * with auto-renew off, and those with a cancellation scheduled inside the
 * current period. Billing one anyway would invoice a customer for a service
 * that is off or already ending.
 */
final class SubscriptionNotRenewableException extends DomainException
{
    public static function forStatus(string $subscriptionId, SubscriptionStatus $status): self
    {
        $exception = new self(sprintf(
            'Subscription %s is %s and cannot be renewed.',
            $subscriptionId,
            $status->value,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'status' => $status->value,
        ]);
    }

    public static function becauseAutoRenewIsOff(string $subscriptionId): self
    {
        $exception = new self(sprintf(
            'Subscription %s has auto-renew disabled and cannot be renewed.',
            $subscriptionId,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'reason' => 'auto_renew_disabled',
        ]);
    }

    public static function becauseCancellationIsScheduled(string $subscriptionId): self
    {
        $exception = new self(sprintf(
            'Subscription %s is scheduled to cancel at the end of the current period.',
            $subscriptionId,
        ));

        return $exception->withContext([
            'subscription_id' => $subscriptionId,
            'reason' => 'cancellation_scheduled',
        ]);
    }

    public function errorCode(): string
    {
        return 'subscription.not_renewable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
