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
 *
 * Either way, the moment the subscription actually ends its open invoices are
 * withdrawn ({@see WindUpAnEndedSubscription}): an unpaid one is voided, and a
 * partly paid one has what it still holds returned to the wallet and is then
 * voided. Left open, an upgrade's proration or a renewal stayed payable by
 * card and by wallet for a subscription that would deliver nothing more, and
 * paying the upgrade queued a resize onto the cancelled subscription's
 * machine (O-1).
 *
 * Cancellation at the end of the period, decided: scheduling it withdraws
 * nothing. The subscription is still active and its service running until
 * cancel_at, so an invoice it has open is still for something - an upgrade
 * paid in that time is delivered for the rest of the period. The wind-up
 * happens in applyScheduled(), when it ends, with the same rule as an
 * immediate cancellation: from then on nothing more is delivered, so nothing
 * more is collected, and what a part-paid invoice holds goes back to the
 * wallet. The platform does not pursue an unpaid invoice of an ended
 * subscription - the rule EndTheSubscriptionWithItsService already applied to
 * a service that ended - and an operator who wants that money asks for it.
 */
final readonly class CancelSubscription
{
    public function __construct(
        private TransitionSubscription $transition,
        private WindUpAnEndedSubscription $windUp,
    ) {}

    public function execute(
        Subscription $subscription,
        bool $immediately = false,
        ?DateTimeImmutable $at = null,
    ): Subscription {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        if ($immediately) {
            return $this->windUp->execute(
                $subscription,
                'cancelled',
                fn (): Subscription => $this->transition->execute($subscription, SubscriptionStatus::Cancelled, $now),
            );
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
     * The sweep that makes a scheduled cancellation actually happen.
     *
     * Recording cancel_at stops the subscription being renewed, but on its own
     * it leaves the status active for ever: the service keeps running and
     * nothing is billing for it. This moves the subscription to cancelled once
     * the date it was promised has arrived, stamping the ending at cancel_at
     * rather than at whatever hour the sweep happened to run, and withdraws
     * its open invoices with it (see the class docblock).
     */
    public function applyScheduled(Subscription $subscription, ?DateTimeImmutable $at = null): Subscription
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return $this->windUp->execute($subscription, 'cancelled at the end of its period', function () use ($subscription, $now): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            // Re-read under the lock: a customer who revoked the cancellation
            // between the sweep's selection and this row lock must not have
            // their service switched off anyway.
            if ($locked->cancel_at === null || $locked->cancel_at->greaterThan($now)) {
                return $locked;
            }

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            return $this->transition->execute($locked, SubscriptionStatus::Cancelled, $locked->cancel_at);
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
