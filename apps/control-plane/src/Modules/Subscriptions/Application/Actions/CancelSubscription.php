<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Ends a subscription, now or at the end of the paid period.
 *
 * Scheduled is the default because the customer has already paid for the
 * current period: switching the service off the moment they click cancel takes
 * back time they own. The scheduled form therefore leaves the status alone —
 * the service keeps running — and only records that nothing further should be
 * billed, which the renewal worker reads through the due-for-renewal scope.
 *
 * Immediate cancellation exists for the cases where continuing to serve is the
 * wrong answer: a chargeback, an account closure, a customer who asks for the
 * service to stop today.
 */
final readonly class CancelSubscription
{
    public function __construct(
        private TransitionSubscription $transition,
    ) {}

    public function execute(
        Subscription $subscription,
        bool $immediately = false,
        ?DateTimeImmutable $at = null,
    ): Subscription {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        if ($immediately) {
            return $this->transition->execute($subscription, SubscriptionStatus::Cancelled, $now);
        }

        return DB::transaction(function () use ($subscription): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            $locked->auto_renew = false;
            // Cancelling twice must not move the date the customer was told:
            // the first request is the one that set it.
            $locked->cancel_at ??= $locked->current_period_end;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Undoes a scheduled cancellation, for a customer who changes their mind
     * before the period ends.
     */
    public function revoke(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            $locked->cancel_at = null;
            $locked->auto_renew = true;
            $locked->save();

            return $locked;
        });
    }
}
