<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Subscriptions\Domain\StateMachines\SubscriptionStateMachine;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The only place a subscription's status changes.
 *
 * Dunning, cancellation and reactivation all arrive from different places — a
 * webhook, a nightly sweep, an operator — and all of them can arrive twice.
 * Funnelling them through one action buys the same properties as TransitionOrder
 * does for orders: every change is checked against the state machine, a
 * re-entrant transition converges instead of throwing, and the row is re-read
 * under a lock so two workers cannot both act on a stale status.
 */
final readonly class TransitionSubscription
{
    public function __construct(
        private SubscriptionStateMachine $stateMachine,
    ) {}

    /**
     * @throws IllegalStateTransitionException
     */
    public function execute(
        Subscription $subscription,
        SubscriptionStatus $to,
        ?DateTimeImmutable $at = null,
    ): Subscription {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        /*
         * Every decision below is taken on the locked row and never on the
         * caller's copy. A model handed to this action may have been read
         * minutes ago — a dunning sweep's cursor, a webhook payload resolved
         * before the queue picked it up — and deciding on it both ways round
         * is wrong: a stale copy that already reads past_due would make a real
         * active → past_due move silently do nothing, and a stale copy that
         * reads cancelled would refuse a transition the row actually allows.
         */
        return DB::transaction(function () use ($subscription, $to, $now): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            // A retried job or a redelivered webhook lands here; converging is
            // the point, and it must not restamp a timestamp that already
            // recorded when the change really happened.
            if ($locked->status === $to) {
                return $locked;
            }

            // The authoritative "from" is the locked row, not the caller's
            // possibly stale copy: a suspension sweep and a payment webhook
            // routinely race for the same subscription.
            $this->stateMachine->assertCanTransition($locked->status, $to);

            $locked->status = $to;
            $this->stampTimestamps($locked, $to, $now);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Keeps the lifecycle timestamps in step with the status.
     */
    private function stampTimestamps(Subscription $subscription, SubscriptionStatus $to, CarbonImmutable $now): void
    {
        match ($to) {
            SubscriptionStatus::Active => $this->clearDunningClocks($subscription),
            SubscriptionStatus::Suspended => $subscription->suspended_at ??= $now,
            SubscriptionStatus::Cancelled => $this->stampEnding($subscription, $now, cancelled: true),
            SubscriptionStatus::Terminated => $this->stampEnding($subscription, $now, cancelled: false),
            SubscriptionStatus::PastDue => null,
        };
    }

    /**
     * Recovery wipes the dunning clocks.
     *
     * They describe one in-flight dunning run, not history. Leaving a stale
     * grace deadline behind would make the customer's next failed payment
     * inherit a deadline that has already expired and suspend them the same
     * day, months after the failure that set it.
     */
    private function clearDunningClocks(Subscription $subscription): void
    {
        $subscription->grace_period_ends_at = null;
        $subscription->suspended_at = null;
    }

    private function stampEnding(Subscription $subscription, CarbonImmutable $now, bool $cancelled): void
    {
        if ($cancelled) {
            $subscription->cancelled_at ??= $now;
        }

        $subscription->ended_at ??= $now;

        // Nothing further is owed for a subscription that has ended, and the
        // renewal worker selects on this column: clearing it is what stops a
        // terminated service from being invoiced again.
        $subscription->next_invoice_at = null;
        $subscription->auto_renew = false;
    }
}
