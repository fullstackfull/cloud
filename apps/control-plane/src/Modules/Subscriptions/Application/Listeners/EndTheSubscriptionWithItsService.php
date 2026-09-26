<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
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
 * And its unpaid invoices are voided (withdrawWhatItStillAsksFor()), so an
 * open renewal cannot be paid for something that no longer exists.
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
        private VoidInvoice $voidInvoice,
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

        if (! $subscription->status->isTerminal()) {
            $this->transition->execute(
                $subscription,
                $subscription->status === SubscriptionStatus::Suspended
                    ? SubscriptionStatus::Terminated
                    : SubscriptionStatus::Cancelled,
            );

            Log::info('A subscription was ended because the service it pays for ended.', [
                'subscription_id' => $subscriptionId,
                'service_id' => $serviceId,
            ]);
        }

        $this->withdrawWhatItStillAsksFor($subscriptionId);
    }

    /**
     * Voids the subscription's invoices that are still collectible and have
     * taken no money.
     *
     * An open renewal invoice — issued before the service ended, perhaps the
     * one dunning was chasing — would otherwise stay payable for a service
     * that no longer exists, and paying it used to reach
     * ReviveSubscriptionOnRenewalPayment, which cannot revive an ended
     * subscription and threw on every retry. Voided, it cannot open a
     * payment, and a capture already in flight for it is credited to the
     * wallet by CompensateUncollectableCapture.
     *
     * An invoice that has taken part of its money is left open and logged:
     * VoidInvoice refuses it, rightly, and what is owed or returned on it is
     * an operator's decision.
     */
    private function withdrawWhatItStillAsksFor(string $subscriptionId): void
    {
        $invoices = Invoice::query()->where('subscription_id', $subscriptionId)->get();

        foreach ($invoices as $invoice) {
            if (! $invoice->status->isCollectible()) {
                continue;
            }

            if ($invoice->amountPaid()->isPositive()) {
                Log::warning('A subscription ended with a partly paid invoice still open; it was left for an operator.', [
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => (string) $invoice->getKey(),
                ]);

                continue;
            }

            $this->voidInvoice->execute($invoice, 'the service this subscription paid for has ended');
        }
    }
}
