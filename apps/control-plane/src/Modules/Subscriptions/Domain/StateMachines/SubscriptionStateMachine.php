<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\StateMachines;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The subscription lifecycle.
 *
 * The dunning path — active → past_due → suspended → terminated — is one-way
 * only in the sense that each step is worse than the last; every step before
 * termination has a way back to active, because the overwhelmingly common
 * cause of a failed recurring payment is an expired card rather than a
 * customer who has decided to stop paying.
 *
 * Two absences are deliberate:
 *
 *  - There is no active → terminated edge. Termination destroys data, so it is
 *    always preceded by suspension, which gives the customer a service that is
 *    visibly off and an operator a window in which to intervene.
 *  - There is no way out of cancelled or terminated. Resuming a subscription
 *    whose service has been torn down would bill for something that no longer
 *    exists; the customer buys again, which produces a new order, a new
 *    subscription and a new provisioning run.
 *
 * @extends AbstractStateMachine<SubscriptionStatus>
 */
final class SubscriptionStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Subscription';
    }

    /**
     * @return array<string, list<SubscriptionStatus>>
     */
    public function transitions(): array
    {
        return [
            SubscriptionStatus::Active->value => [
                // A recurring payment failed and dunning has started.
                SubscriptionStatus::PastDue,
                // An operator suspending for abuse, not for non-payment.
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Cancelled,
            ],

            SubscriptionStatus::PastDue->value => [
                // The customer paid, or fixed their card. This is the path the
                // majority of past-due subscriptions actually take.
                SubscriptionStatus::Active,
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Cancelled,
            ],

            SubscriptionStatus::Suspended->value => [
                // Payment after suspension restores the service rather than
                // requiring the customer to buy the same thing again.
                SubscriptionStatus::Active,
                SubscriptionStatus::Terminated,
                SubscriptionStatus::Cancelled,
            ],

            // Terminal. A cancelled subscription ended by the customer's
            // choice and a terminated one by ours; neither is a workflow that
            // continues.
            SubscriptionStatus::Cancelled->value => [],
            SubscriptionStatus::Terminated->value => [],
        ];
    }
}
