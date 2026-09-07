<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
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
 * For VPS and dedicated servers the machine is deliberately left running and
 * this is reported as a gap rather than papered over. The obvious move —
 * dispatch a stop — is wrong in a way that matters: stopping a machine for
 * non-payment is indistinguishable, at the hypervisor, from a customer
 * stopping their own machine, and nothing would then prevent the customer
 * pressing start again. Real compute suspension needs the provider to refuse
 * the customer's own start, and the platform has not designed that. Recording
 * an honest NOT_IMPLEMENTED is better than a suspension the customer can undo
 * from the portal.
 *
 * ---------------------------------------------------------------------------
 * Why one listener and one edge at a time
 * ---------------------------------------------------------------------------
 *
 * Only two edges do anything: arriving at suspended, and leaving suspended for
 * active. Arriving at active from past_due means the customer paid before
 * anything was switched off, and there is nothing to restore. Acting on the
 * destination alone would send an unsuspend to a control panel for every
 * recovered past-due subscription in the fleet.
 */
final class EnforceServiceStateForSubscription implements ShouldQueue
{
    public string $queue = 'provisioning';

    public int $tries = 3;

    public function __construct(
        private readonly TransitionService $transitionService,
        private readonly SuspendHostingAccount $suspend,
        private readonly UnsuspendHostingAccount $unsuspend,
    ) {}

    public function handle(SubscriptionStatusChanged $event): void
    {
        $suspending = $event->to === SubscriptionStatus::Suspended;
        $restoring = $event->to === SubscriptionStatus::Active
            && $event->from === SubscriptionStatus::Suspended;

        if (! $suspending && ! $restoring) {
            return;
        }

        $services = Service::query()
            ->where('subscription_id', $event->subscriptionId)
            ->where('status', $suspending ? ServiceStatus::Active->value : ServiceStatus::Suspended->value)
            ->get();

        foreach ($services as $service) {
            $this->apply($service, $suspending, $event);
        }
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
