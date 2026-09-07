<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Lynomia\Modules\Billing\Domain\Exceptions\SubscriptionAlreadyEndedException;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * A cancellation asked for by the customer who owns the subscription.
 *
 * The mechanics belong to CancelSubscription and are not repeated here: this
 * action adds the one precondition the customer surface needs and delegates.
 *
 * The precondition is that the subscription has not already ended. The
 * underlying action is built for the platform's own callers — a dunning sweep,
 * an operator, a chargeback handler — and its scheduled form deliberately does
 * no status checking, because it is the form a *sweep* uses. Asked of a
 * cancelled subscription it would happily stamp a fresh `cancel_at` onto a
 * dead row and report success. Refusing here, in an action rather than in the
 * controller, keeps that refusal available to a queue worker or a console
 * command rather than only to something that arrives over HTTP.
 *
 * There is no idempotency key. A cancellation neither spends money nor
 * provisions hardware, and repeating one converges by construction: the
 * scheduled form leaves the first date it recorded alone (`cancel_at ??=`),
 * and the immediate form goes through TransitionSubscription, which returns
 * the row unchanged when it is already in the target state. A key here would
 * be a second mechanism guarding something that is already safe to repeat.
 */
final readonly class CancelCustomerSubscription
{
    public function __construct(
        private CancelSubscription $cancelSubscription,
    ) {}

    /**
     * @param  bool  $immediately  true ends the subscription now; false — the
     *                             default — lets it run out the period the
     *                             customer has already paid for.
     *
     * @throws SubscriptionAlreadyEndedException
     */
    public function execute(Subscription $subscription, bool $immediately = false): Subscription
    {
        if ($subscription->status->isTerminal()) {
            throw SubscriptionAlreadyEndedException::forStatus(
                (string) $subscription->getKey(),
                $subscription->status,
            );
        }

        return $this->cancelSubscription->execute($subscription, immediately: $immediately);
    }
}
