<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Subscriptions\Application\Actions\AdvanceDunning;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The customer paid what they owed, so stop treating them as delinquent.
 *
 * Without this the money arrived and nothing happened. A renewal invoice being
 * paid announced InvoicePaid, the only listener on it cared about orders and
 * returned immediately for a renewal, and the subscription stayed exactly
 * where dunning had left it — past_due, or suspended and counting down to
 * termination. The customer had paid, in full, and the nightly sweep went on
 * to terminate them anyway.
 *
 * AdvanceDunning::recordSuccessfulPayment has always known how to unwind all
 * of this. Nothing called it.
 *
 * Queued on payments beside the other settlement work, because reviving a
 * subscription hands off to service restoration, which talks to hypervisors
 * and control panels; doing that inside the request that captured the payment
 * would make a customer's card charge wait on a cPanel API.
 */
final class ReviveSubscriptionOnRenewalPayment implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    public function __construct(
        private readonly AdvanceDunning $dunning,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if ($event->subscriptionId === null) {
            // A first-purchase invoice. Its order is fulfilled by the
            // settlement chain and there is no dunning state to unwind.
            return;
        }

        $subscription = Subscription::query()->find($event->subscriptionId);

        if ($subscription === null) {
            /*
             * Recorded rather than thrown. A paid invoice pointing at a
             * subscription that no longer exists is a data fault worth
             * knowing about, but retrying it five times cannot make the row
             * reappear, and failing the job would leave a settled payment
             * looking like it had not been processed.
             */
            Log::warning('A paid renewal invoice names a subscription that does not exist.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        /*
         * recordSuccessfulPayment is idempotent by construction: it resets the
         * dunning counters whether or not the status moves, and
         * TransitionSubscription converges when the subscription is already
         * active. A redelivered webhook therefore costs nothing.
         */
        $this->dunning->recordSuccessfulPayment($subscription);
    }
}
