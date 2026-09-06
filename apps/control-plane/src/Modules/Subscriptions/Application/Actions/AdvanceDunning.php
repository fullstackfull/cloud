<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Dunning: what happens between a payment failing and a service being torn
 * down.
 *
 * The schedule is deliberately slow. A subscription whose payment fails moves
 * to past_due and *keeps running*: the overwhelming majority of failed
 * recurring payments are expired or reissued cards, not customers who have
 * decided to stop paying, and cutting the service the moment a card declines
 * turns a customer who would have paid within the week into a customer who has
 * already had an outage and now has a reason to leave. The service only stops
 * once the grace period has expired — at which point non-payment, rather than
 * a payment method, is the likeliest explanation — and data is only destroyed
 * a further termination window after that.
 *
 * Every clock here is read from config so the commercial policy can be changed
 * without touching the sequence it drives:
 *  - billing.grace_period_days      how long past_due keeps the service up;
 *  - billing.termination_after_days how long a suspended service survives.
 */
final readonly class AdvanceDunning
{
    public function __construct(
        private TransitionSubscription $transition,
    ) {}

    /**
     * A recurring payment failed.
     *
     * @throws IllegalStateTransitionException
     */
    public function recordFailedPayment(Subscription $subscription, ?DateTimeImmutable $at = null): Subscription
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $now): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            if ($locked->status->isTerminal()) {
                // A payment attempt against an ended subscription is a
                // mis-routed charge, not a dunning event.
                throw IllegalStateTransitionException::between(
                    'Subscription',
                    $locked->status,
                    SubscriptionStatus::PastDue,
                );
            }

            $locked->failed_payment_count = $locked->failed_payment_count + 1;

            /*
             * The grace clock starts at the first failure and is never
             * extended. Retries are scheduled at 24, 72 and 168 hours, so
             * restarting the clock on each one would push the deadline out
             * past the retries themselves and nothing would ever suspend.
             */
            $locked->grace_period_ends_at ??= $now->addDays($this->graceDays());
            $locked->save();

            if ($locked->status === SubscriptionStatus::Active) {
                return $this->transition->execute($locked, SubscriptionStatus::PastDue, $now);
            }

            return $locked;
        });
    }

    /**
     * The customer paid: unwind everything dunning did.
     *
     * @throws IllegalStateTransitionException
     */
    public function recordSuccessfulPayment(Subscription $subscription, ?DateTimeImmutable $at = null): Subscription
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $now): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            // Reset even when the status is not moving. A payment that lands
            // while the subscription is still active — a retry that succeeded
            // before the failure was processed — must not leave a half-run
            // dunning clock behind for the next failure to inherit.
            $locked->failed_payment_count = 0;
            $locked->grace_period_ends_at = null;
            $locked->suspended_at = null;
            $locked->save();

            return $this->transition->execute($locked, SubscriptionStatus::Active, $now);
        });
    }

    /**
     * The scheduled sweep: move one subscription to whatever the clock now
     * says it should be.
     *
     * One step per run, on purpose. Suspension is what starts the termination
     * clock, so collapsing both steps into a single sweep would destroy a
     * customer's data in the same instant their service went off, with no
     * window in which anyone could notice.
     */
    public function execute(Subscription $subscription, ?DateTimeImmutable $at = null): Subscription
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($subscription, $now): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            if ($this->graceHasExpired($locked, $now)) {
                return $this->transition->execute($locked, SubscriptionStatus::Suspended, $now);
            }

            if ($this->suspensionHasExpired($locked, $now)) {
                return $this->transition->execute($locked, SubscriptionStatus::Terminated, $now);
            }

            return $locked;
        });
    }

    private function graceHasExpired(Subscription $subscription, CarbonImmutable $now): bool
    {
        return $subscription->status === SubscriptionStatus::PastDue
            && $subscription->grace_period_ends_at !== null
            && $subscription->grace_period_ends_at->lessThanOrEqualTo($now);
    }

    private function suspensionHasExpired(Subscription $subscription, CarbonImmutable $now): bool
    {
        return $subscription->status === SubscriptionStatus::Suspended
            && $subscription->suspended_at !== null
            && $subscription->suspended_at->addDays($this->terminationDays())->lessThanOrEqualTo($now);
    }

    private function graceDays(): int
    {
        return (int) config('billing.grace_period_days', 7);
    }

    private function terminationDays(): int
    {
        return (int) config('billing.termination_after_days', 14);
    }
}
