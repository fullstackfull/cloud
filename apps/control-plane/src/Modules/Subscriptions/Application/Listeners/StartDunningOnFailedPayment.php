<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Subscriptions\Application\Actions\AdvanceDunning;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * A renewal payment failed, so start the clock.
 *
 * The mirror of ReviveSubscriptionOnRenewalPayment, and the half that was
 * missing for longer. RecordPaymentFailure raised PaymentFailed and nothing
 * listened; AdvanceDunning::recordFailedPayment — which counts the failure,
 * opens the grace window and moves the subscription to past_due — had no
 * caller at all.
 *
 * The consequence was the opposite of the one people expect from a billing
 * bug, and worse for the business than for the customer: a card that expired
 * cost nothing. The subscription stayed active, the lifecycle sweep looks only
 * at past_due and suspended subscriptions so it never saw it, and the service
 * ran indefinitely for somebody who had stopped paying. The only way into
 * dunning was an operator moving a status by hand — a case AdvanceDunning
 * anticipated in a comment and which was, in practice, the only way it ever
 * ran.
 *
 * Note what this does NOT do: it does not suspend anything. Failing a payment
 * opens a grace period, and the sweep is what eventually closes it. A listener
 * that took a customer offline the instant their card was declined would take
 * them offline for a bank's overnight maintenance window.
 */
final class StartDunningOnFailedPayment implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    public function __construct(
        private readonly AdvanceDunning $dunning,
    ) {}

    public function handle(PaymentFailed $event): void
    {
        $subscription = $this->subscriptionBehind($event);

        if ($subscription === null) {
            // A first purchase, a wallet top-up, or an attempt against an
            // invoice with no subscription. There is no recurring commitment
            // to put into dunning; the order simply stays unpaid.
            return;
        }

        try {
            $this->dunning->recordFailedPayment($subscription);
        } catch (IllegalStateTransitionException $e) {
            /*
             * Recorded rather than retried. A cancelled or terminated
             * subscription cannot enter dunning, and a late webhook for a
             * payment against one is exactly how this arrives. Retrying five
             * times cannot make the transition legal.
             */
            Log::info('A failed payment arrived for a subscription that cannot enter dunning.', [
                'subscription_id' => (string) $subscription->getKey(),
                'status' => $subscription->status->value,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function subscriptionBehind(PaymentFailed $event): ?Subscription
    {
        if ($event->invoiceId === null) {
            return null;
        }

        /*
         * Reached through the invoice rather than the customer. A customer can
         * hold several subscriptions, and charging the dunning counter of
         * whichever one was found first would suspend a service the failed
         * payment had nothing to do with.
         */
        $invoice = Invoice::query()->find($event->invoiceId);

        if ($invoice?->subscription_id === null) {
            return null;
        }

        return Subscription::query()->find($invoice->subscription_id);
    }
}
