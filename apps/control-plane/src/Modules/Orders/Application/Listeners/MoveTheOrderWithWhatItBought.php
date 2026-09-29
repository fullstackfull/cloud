<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Orders\Application\Actions\KeepTheOrderInStepWithItsServices;
use Lynomia\Modules\Provisioning\Application\Queries\WhatAnOrderBrought;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobStarted;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Throwable;

/**
 * Wakes the order whenever something it bought moves (F-19).
 *
 * Four announcements, one question. A service changing status is the ordinary
 * case; a build being claimed by a worker is the one moment in a build that
 * moves no service status; and a build stopping — for review, or refused — is
 * heard directly as well as through the service, because a build a stale-job
 * sweep parks for review leaves its service where it was. Whatever woke it,
 * KeepTheOrderInStepWithItsServices reads the facts afresh.
 *
 * ---------------------------------------------------------------------------
 * Synchronous, and it never throws
 * ---------------------------------------------------------------------------
 *
 * It runs where the move happened — inside a provisioning worker, an operator's
 * request, a subscription listener — so the order is current when that returns,
 * with no queue between the two to drain. The price is that its failure must
 * not become theirs: an exception here would leave a build claimed and never
 * run, or an operator's suspension half-applied, over a status that is only
 * the order's summary of facts that are already true. So a failure is logged
 * and swallowed. The next move of anything the order bought repeats the whole
 * question, and the order catches up then.
 */
final readonly class MoveTheOrderWithWhatItBought
{
    public function __construct(
        private KeepTheOrderInStepWithItsServices $follow,
        private WhatAnOrderBrought $brought,
    ) {}

    public function handle(ServiceStatusChanged|ProvisioningJobStarted|ProvisioningJobNeedsReview|ProvisioningJobFailed $event): void
    {
        $orderId = $this->orderFor($event);

        if ($orderId === null) {
            return;
        }

        try {
            $this->follow->execute($orderId, $this->reasonFor($event));
        } catch (Throwable $e) {
            Log::warning('An order could not be moved in step with what it bought; the next change to any of its services will try again.', [
                'order_id' => $orderId,
                'event' => $event::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function orderFor(ServiceStatusChanged|ProvisioningJobStarted|ProvisioningJobNeedsReview|ProvisioningJobFailed $event): ?string
    {
        if ($event instanceof ServiceStatusChanged) {
            return $event->orderId;
        }

        // Only a build says anything about the purchase. A reboot, a resize or
        // a reinstall starting or failing is not a change in what was bought.
        if (! $event->kind->createsResource()) {
            return null;
        }

        return $this->brought->orderBehind($event->serviceId);
    }

    private function reasonFor(ServiceStatusChanged|ProvisioningJobStarted|ProvisioningJobNeedsReview|ProvisioningJobFailed $event): string
    {
        return match (true) {
            $event instanceof ServiceStatusChanged => sprintf(
                'service %s moved from %s to %s',
                $event->serviceId,
                $event->from->value,
                $event->to->value,
            ),
            $event instanceof ProvisioningJobStarted => sprintf('build job %s started (attempt %d)', $event->provisioningJobId, $event->attempt),
            $event instanceof ProvisioningJobNeedsReview => sprintf('build job %s stopped for review', $event->provisioningJobId),
            default => sprintf('build job %s failed', $event->provisioningJobId),
        };
    }
}
