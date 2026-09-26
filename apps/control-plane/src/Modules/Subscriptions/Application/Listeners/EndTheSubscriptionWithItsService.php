<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Application\Actions\WindUpAnEndedSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Throwable;

/**
 * A service that has ended stops being billed for (I-1).
 *
 * The subscription drove the service in one direction only. Suspending or
 * cancelling a subscription reaches the machine or the account
 * (EnforceServiceStateForSubscription), but a service ended from the other
 * side — an operator's forced `DELETE /api/admin/services/{id}`, the
 * hosting-account route, a VPS whose destroy job finished — left its
 * subscription `active`, and RenewDueSubscriptions went on issuing invoices
 * for something that no longer existed. The re-audit after round two measured
 * it for Shared Hosting (`service=terminated order=terminated sub=active`,
 * then `renewed=1`); the VPS forced path has the same shape.
 *
 * So whatever ended the service, its arrival at TERMINATED ends the
 * subscription it was paid through, once every service on that subscription
 * has ended:
 *
 *  - SUSPENDED goes to TERMINATED — the dunning path's own ending, and an edge
 *    the state machine already has.
 *  - ACTIVE and PAST_DUE go to CANCELLED. The state machine deliberately has no
 *    ACTIVE → TERMINATED edge (termination is always preceded by suspension,
 *    and SubscriptionStateMachineTest pins it), and CANCELLED is the terminal
 *    state reachable from both. Either way TransitionSubscription clears
 *    `next_invoice_at` and `auto_renew`, which is what the renewal sweep
 *    selects on.
 *
 * And its open invoices are withdrawn ({@see WindUpAnEndedSubscription}), so
 * an open renewal cannot be paid for something that no longer exists: an
 * unpaid one is voided, and a partly paid one has what it still holds
 * returned to the wallet, recorded against it, and is voided too. A partly
 * paid invoice used to be left open "for an operator", and the customer could
 * pay the rest of it for a service already terminated (N-3).
 *
 * Arriving at CANCELLED is heard by EnforceServiceStateForSubscription, which
 * acts only on services that are still ACTIVE or SUSPENDED — none, by the time
 * this runs — and by NotifyOnSubscriptionChange, which sends nothing for it.
 *
 * Synchronous, and it never throws, for MoveTheOrderWithWhatItBought's
 * reason: it runs where the service moved — an operator's request that has
 * already deleted a site at the panel, a provisioning worker that has already
 * destroyed a machine — and its failure must not become theirs. A failure is
 * logged; RenewSubscription skips a subscription whose service is TERMINATED
 * all the same, so a subscription this could not move is still not invoiced.
 */
final readonly class EndTheSubscriptionWithItsService
{
    public function __construct(
        private TransitionSubscription $transition,
        private WindUpAnEndedSubscription $windUp,
    ) {}

    public function handle(ServiceStatusChanged $event): void
    {
        if ($event->to !== ServiceStatus::Terminated) {
            return;
        }

        $subscriptionId = Service::query()->whereKey($event->serviceId)->value('subscription_id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        try {
            $this->end($subscriptionId, $event->serviceId);
        } catch (Throwable $e) {
            Log::warning('A service ended and its subscription could not be ended with it; renewal skips it regardless.', [
                'service_id' => $event->serviceId,
                'subscription_id' => $subscriptionId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function end(string $subscriptionId, string $serviceId): void
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null) {
            return;
        }

        $stillLive = Service::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', '!=', ServiceStatus::Terminated->value)
            ->exists();

        if ($stillLive) {
            // Something else this subscription pays for is still there.
            return;
        }

        // Its invoices first, then the subscription, then the withdrawals:
        // the wind-up takes them in the money-path lock order, the one a
        // renewal takes an invoice and its subscription in.
        $this->windUp->execute($subscription, 'the service it paid for has ended', function () use ($subscription, $subscriptionId, $serviceId): void {
            /** @var Subscription $locked */
            $locked = Subscription::query()->findOrFail($subscription->getKey());

            if ($locked->status->isTerminal()) {
                return;
            }

            $this->transition->execute(
                $locked,
                $locked->status === SubscriptionStatus::Suspended
                    ? SubscriptionStatus::Terminated
                    : SubscriptionStatus::Cancelled,
            );

            Log::info('A subscription was ended because the service it pays for ended.', [
                'subscription_id' => $subscriptionId,
                'service_id' => $serviceId,
            ]);
        });
    }
}
