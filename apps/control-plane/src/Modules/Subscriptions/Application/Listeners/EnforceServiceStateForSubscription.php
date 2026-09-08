<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\Actions\HoldBackupsThroughRetention;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Application\Actions\EnforceComputeSuspension;
use Lynomia\Modules\Compute\Application\Actions\LiftComputeSuspension;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Actions\SuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\UnsuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;

/**
 * Makes a subscription's status mean something to the thing it pays for.
 *
 * Before this listener existed the two halves of the platform were not
 * connected in either direction. A subscription could go past_due, suspended
 * and on to terminated while the customer's server ran normally the whole
 * time — nothing ever switched anything off for non-payment. And when the
 * customer paid, nothing switched anything back on, because nothing had
 * switched it off. SuspendHostingAccount and UnsuspendHostingAccount had both
 * been written and neither had a caller.
 *
 * ---------------------------------------------------------------------------
 * What enforcement means here
 * ---------------------------------------------------------------------------
 *
 * The service row moves to suspended, and that is not cosmetic: VpsOperationGuard
 * refuses every power, resize and reinstall request for a service that is not
 * active, and IssueHostingPanelSession refuses a suspended account. A suspended
 * customer keeps their data and loses control of it, which is the point — the
 * termination sweep, days later, is what destroys anything.
 *
 * For shared hosting the control panel is told as well, because the account
 * serves web traffic that the platform does not sit in front of; leaving the
 * site up would make suspension mean nothing at all to the person who owes
 * money.
 *
 * For VPS the hypervisor is told as well, under the configured suspension
 * policy. Phase 29 deliberately refused to do this as a bare stop, and the
 * reasoning was right: a stop is indistinguishable at the hypervisor from the
 * customer stopping their own machine, and nothing about a stopped VM prevents
 * it being started again. What makes it enforcement is the rest of the policy —
 * clearing onboot so a node reboot does not resurrect it, and setting Proxmox's
 * config lock so that even `qm start` on the node is refused until the platform
 * lifts it.
 *
 * Reactivation is the asymmetric half. Suspending and then discovering the
 * provider did not comply leaves a customer using something they have not paid
 * for, which is a bill; reactivating and discovering the same leaves a customer
 * who HAS paid unable to use their server, which is an outage. So the service
 * goes to `reactivating` and only reaches `active` once the provider confirms —
 * and if it does not, it stays unusable and says so rather than pretending.
 *
 * ---------------------------------------------------------------------------
 * Cancellation is the third edge, and it was missing
 * ---------------------------------------------------------------------------
 *
 * A cancelled subscription is one nobody is paying for any more — the customer
 * asked for it to end, and the period they paid for has run out. Until this
 * edge existed, that meant nothing at all to the thing it paid for: the
 * machine kept running, the hosting account kept serving, and the platform
 * kept the disk. Free for the customer, and permanent.
 *
 * Arriving at cancelled therefore does everything arriving at suspended does —
 * the row moves, the provider is told — and one thing more: the retention
 * window is started and written down, so that both the customer and the sweep
 * know the date the data goes. Suspension for non-payment starts the same
 * window with a different reason, and the difference decides what may happen
 * automatically at the end of it.
 *
 * ---------------------------------------------------------------------------
 * Why one listener and one edge at a time
 * ---------------------------------------------------------------------------
 *
 * Only three edges do anything: arriving at suspended, arriving at cancelled,
 * and leaving suspended for active. Arriving at active from past_due means the
 * customer paid before anything was switched off, and there is nothing to
 * restore. Acting on the destination alone would send an unsuspend to a
 * control panel for every recovered past-due subscription in the fleet.
 */
final class EnforceServiceStateForSubscription implements ShouldQueue
{
    public string $queue = 'provisioning';

    public int $tries = 3;

    public function __construct(
        private readonly TransitionService $transitionService,
        private readonly SuspendHostingAccount $suspend,
        private readonly UnsuspendHostingAccount $unsuspend,
        private readonly EnforceComputeSuspension $suspendCompute,
        private readonly LiftComputeSuspension $liftCompute,
        private readonly NotifyCustomer $notify,
        private readonly BeginRetentionWindow $retention,
        private readonly HoldBackupsThroughRetention $holdBackups,
    ) {}

    public function handle(SubscriptionStatusChanged $event): void
    {
        $ending = $event->to === SubscriptionStatus::Cancelled;
        $suspending = $event->to === SubscriptionStatus::Suspended || $ending;
        $restoring = $event->to === SubscriptionStatus::Active
            && $event->from === SubscriptionStatus::Suspended;

        if (! $suspending && ! $restoring) {
            return;
        }

        $services = Service::query()
            ->where('subscription_id', $event->subscriptionId)
            ->whereIn('status', $suspending
                /*
                 * A cancellation may arrive at a service that is already
                 * suspended — the customer stopped paying, was suspended, and
                 * then cancelled. There is nothing to switch off, but the
                 * window's reason changes from "has not paid" to "asked to
                 * leave", and that is what decides whether the sweep may act.
                 */
                ? ($ending
                    ? [ServiceStatus::Active->value, ServiceStatus::Suspended->value]
                    : [ServiceStatus::Active->value])
                // Reactivating is included so a reactivation that failed and
                // was retried is picked up rather than skipped for being
                // already halfway.
                : [ServiceStatus::Suspended->value, ServiceStatus::Reactivating->value])
            ->get();

        foreach ($services as $service) {
            /*
             * A cancellation that reaches a service already switched off has
             * nothing to switch off — the customer stopped paying, was
             * suspended, and has now decided to leave. Only the window's
             * reason changes. Every other edge, including a restoration, goes
             * through the enforcement below.
             */
            $alreadyStopped = $ending && $service->status === ServiceStatus::Suspended;

            if (! $alreadyStopped) {
                $this->apply($service, $suspending, $event);
            }

            if ($suspending) {
                $this->startTheClock($service, $ending);
            }

            if ($restoring) {
                // Paid, and staying. The date the data was going to be
                // destroyed on stops existing rather than sitting in the row
                // where the next sweep would read it.
                $this->retention->cancel($service);
            }
        }
    }

    /**
     * Records when this service's data goes, and holds its backups until then.
     *
     * Both are written even for a suspension, because a suspension is where
     * the window starts — but the reason differs, and the sweep will only ever
     * act on its own for the one the customer chose.
     */
    private function startTheClock(Service $service, bool $ending): void
    {
        $stamped = $this->retention->execute(
            $service,
            $ending ? BeginRetentionWindow::BY_CUSTOMER : BeginRetentionWindow::BY_NON_PAYMENT,
        );

        if ($stamped->retention_ends_at === null) {
            return;
        }

        /*
         * The producer `protected_until` never had. Until now a departing
         * customer's backups were subject to the ordinary retention sweep,
         * which is the one thing the window is supposed to prevent: somebody
         * who cancelled by mistake finding the copies gone before they noticed.
         */
        $this->holdBackups->execute((string) $service->getKey(), $stamped->retention_ends_at);

        if (! $ending) {
            // A suspension for non-payment already sends its own message, and
            // it is a different one: that customer has not left, they owe.
            return;
        }

        /*
         * Sent from here rather than from the notifications listener, because
         * the sentence has to quote the date the data goes and this is where
         * that date is written. Two queued listeners on one event have no
         * order between them, so a message sent from the other one would
         * sometimes quote a date that did not exist yet.
         */
        $this->notify->execute(
            customerId: (string) $stamped->customer_id,
            type: NotificationType::ServiceEnded,
            idempotencyKey: 'service-ended:'.$stamped->getKey(),
            subject: $stamped,
            data: [
                'service' => $stamped->label ?? 'your service',
                'date' => CarbonImmutable::now()->toDateString(),
                'retention_ends' => $stamped->retention_ends_at->toDateString(),
            ],
            link: '/services',
        );
    }

    private function apply(Service $service, bool $suspending, SubscriptionStatusChanged $event): void
    {
        /*
         * The provider is told first, and the row moves only if that
         * succeeded. The other order produces the worst outcome available: a
         * service the platform believes is suspended, still serving traffic,
         * that no later pass will try again because the row already reads
         * suspended.
         */
        if ($service->kind === ProductKind::Vps->value) {
            $this->applyToCompute($service, $suspending, $event);

            return;
        }

        if ($service->kind === ProductKind::SharedHosting->value) {
            try {
                $this->atTheControlPanel($service, $suspending);
            } catch (HostingProviderException $e) {
                /*
                 * Rethrown so the queue retries. A control panel that is down
                 * for a minute must not cost a customer their suspension or
                 * their restoration, and three attempts on the provisioning
                 * queue is the same treatment every other provider call gets.
                 */
                Log::warning('Could not change a hosting account while enforcing a subscription status.', [
                    'subscription_id' => $event->subscriptionId,
                    'service_id' => (string) $service->getKey(),
                    'suspending' => $suspending,
                ]);

                throw $e;
            }
        }

        $this->transitionService->execute(
            $service,
            $suspending ? ServiceStatus::Suspended : ServiceStatus::Active,
        );
    }

    /**
     * Suspension and reactivation for a virtual machine.
     */
    private function applyToCompute(Service $service, bool $suspending, SubscriptionStatusChanged $event): void
    {
        if ($suspending) {
            /*
             * The row moves first here, unlike shared hosting. A hosting
             * account that is still serving under a row that reads suspended
             * is invisible; a VM that is still running under one is caught by
             * the reconciler, which compares the platform's beliefs with the
             * hypervisor on a schedule. And moving first means a provider that
             * refuses does not leave the customer's portal claiming the
             * service is active.
             */
            // Reassigned, not discarded: the action answers with the row it
            // locked, and everything below has to reason about that rather
            // than about the copy the query returned.
            $service = $this->transitionService->execute($service, ServiceStatus::Suspended);

            $enforced = $this->suspendCompute->execute($service);

            if (! $enforced) {
                /*
                 * The platform believes it is suspended and the provider does
                 * not agree. Left as suspended — which is what the customer
                 * should experience — and recorded, because a machine running
                 * unpaid is a bill somebody has to chase.
                 */
                Log::warning('A compute suspension was not confirmed by the provider.', [
                    'service_id' => (string) $service->getKey(),
                    'subscription_id' => $event->subscriptionId,
                ]);
            }

            return;
        }

        // Reactivating, not active. The payment has landed and the machine has
        // not come back; those are two facts separated by a hypervisor call.
        $service = $this->transitionService->execute($service, ServiceStatus::Reactivating);

        $restored = $this->liftCompute->execute($service);

        if ($restored) {
            $this->transitionService->execute($service, ServiceStatus::Active);

            return;
        }

        /*
         * Paid for and still not usable. The service goes back to suspended
         * rather than staying in reactivating, so that the next payment event
         * or an operator retry picks it up through the same path — and the
         * customer is told, because they have every reason to believe the
         * thing they just paid for is working.
         */
        $service = $this->transitionService->execute($service, ServiceStatus::Suspended);

        $this->notify->execute(
            customerId: (string) $service->customer_id,
            type: NotificationType::ServiceReactivationFailed,
            idempotencyKey: sprintf(
                'reactivation-failed:%s:%d',
                $service->getKey(),
                $event->changedAt->getTimestamp(),
            ),
            subject: $service,
            data: ['service' => $this->labelFor($service)],
            link: '/services',
        );

        Log::error('A service was paid for and could not be reactivated at the provider.', [
            'service_id' => (string) $service->getKey(),
            'subscription_id' => $event->subscriptionId,
        ]);
    }

    private function labelFor(Service $service): string
    {
        $label = $service->label;

        return is_string($label) && $label !== '' ? $label : (string) $service->getKey();
    }

    private function atTheControlPanel(Service $service, bool $suspending): void
    {
        $account = HostingAccount::query()
            ->where('service_id', $service->getKey())
            ->first();

        if ($account === null) {
            // A hosting service whose account was never built. There is
            // nothing at the provider to act on, and the row transition below
            // is still correct.
            return;
        }

        if ($suspending) {
            $this->suspend->execute($account, reason: 'Subscription suspended for non-payment.');

            return;
        }

        $this->unsuspend->execute($account);
    }
}
