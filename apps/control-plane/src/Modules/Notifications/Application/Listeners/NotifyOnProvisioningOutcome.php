<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Tells a customer what happened to the thing they bought.
 *
 * These three events were raised and nobody listened, which is how a customer
 * could order a server, have it built, and never be told — or have it fail and
 * find out by logging in and looking at a status badge.
 *
 * ---------------------------------------------------------------------------
 * Not every job is worth a message
 * ---------------------------------------------------------------------------
 *
 * A power cycle succeeding is not news: the customer pressed the button and
 * watched it happen. What is news is the work they cannot watch — a build
 * completing minutes after they closed the tab, a reinstall finishing, or
 * anything at all failing. So success is filtered by kind and failure is not:
 * a customer whose reboot failed needs to know, and one whose reboot worked
 * does not.
 *
 * ---------------------------------------------------------------------------
 * Keyed on the job, not the moment
 * ---------------------------------------------------------------------------
 *
 * The idempotency key is the job id and the outcome. RunProvisioningJob can
 * be redelivered, and a build that succeeded must announce itself once however
 * many times its terminal event is replayed.
 */
final class NotifyOnProvisioningOutcome implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly NotifyCustomer $notify,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ProvisioningJobSucceeded::class => 'succeeded',
            ProvisioningJobFailed::class => 'failed',
            ProvisioningJobNeedsReview::class => 'needsReview',
        ];
    }

    public function succeeded(ProvisioningJobSucceeded $event): void
    {
        if (! $this->isWorthAnnouncing($event->kind)) {
            return;
        }

        $service = $this->service($event->serviceId);

        if ($service === null) {
            return;
        }

        $type = match (true) {
            $this->isAReinstall($event->kind) => NotificationType::ReinstallCompleted,
            // A resize and a package change are only ever created by a plan
            // change, so this is what "your upgrade is done" means: the money
            // moved earlier, and the product has now caught up.
            $this->isAPlanChange($event->kind) => NotificationType::PlanChangeCompleted,
            default => NotificationType::ServiceReady,
        };

        $this->notify->execute(
            customerId: (string) $service->customer_id,
            type: $type,
            idempotencyKey: 'provisioning-succeeded:'.$event->provisioningJobId,
            subject: $service,
            data: ['service' => $this->label($service), 'image' => ''],
            link: '/services',
        );
    }

    public function failed(ProvisioningJobFailed $event): void
    {
        $service = $this->service($event->serviceId);

        if ($service === null) {
            return;
        }

        $type = match (true) {
            $this->isAReinstall($event->kind) => NotificationType::ReinstallFailed,
            /*
             * The case the phase brief singles out: the customer has been
             * charged and their machine is still the old size. Telling them is
             * not optional — the alternative is a customer who paid for an
             * upgrade discovering months later that they never got it.
             */
            $this->isAPlanChange($event->kind) => NotificationType::PlanChangeFailed,
            default => NotificationType::ServiceProvisioningFailed,
        };

        /*
         * The failure class and error code are deliberately not passed to the
         * customer. "capacity_exceeded" is the platform's vocabulary for its
         * own scheduler, and a customer reading it learns nothing they can act
         * on while learning something about the fleet. The operator screen has
         * the detail.
         */
        $this->notify->execute(
            customerId: (string) $service->customer_id,
            type: $type,
            idempotencyKey: 'provisioning-failed:'.$event->provisioningJobId,
            subject: $service,
            data: ['service' => $this->label($service)],
            link: '/services',
        );
    }

    public function needsReview(ProvisioningJobNeedsReview $event): void
    {
        $service = $this->service($event->serviceId);

        if ($service === null) {
            return;
        }

        /*
         * A timeout, usually. The customer is told that a person is looking,
         * and specifically not told the machine failed — the platform does not
         * know that, and telling somebody their server was not built when it
         * may exist is worse than saying nothing precise.
         */
        $this->notify->execute(
            customerId: (string) $service->customer_id,
            type: NotificationType::ServiceNeedsReview,
            idempotencyKey: 'provisioning-review:'.$event->provisioningJobId,
            subject: $service,
            data: ['service' => $this->label($service)],
            link: '/services',
        );
    }

    /**
     * Whether a customer would want to hear that this kind of job worked.
     */
    private function isWorthAnnouncing(ProvisioningJobKind $kind): bool
    {
        return match ($kind) {
            ProvisioningJobKind::CreateVps,
            ProvisioningJobKind::CreateHostingAccount,
            ProvisioningJobKind::ProvisionDedicated,
            ProvisioningJobKind::ReinstallVps,
            ProvisioningJobKind::ReinstallDedicated,
            // A plan change is money the customer spent; they are told when
            // the product finally matches what they bought.
            ProvisioningJobKind::Resize,
            ProvisioningJobKind::ChangeHostingPackage => true,
            // Power operations and destroys: either the customer is watching,
            // or a different message covers it.
            default => false,
        };
    }

    /**
     * Whether this kind rebuilt a machine the customer already had.
     *
     * Both reinstalls read the same to a customer — "the server you have is
     * being rebuilt" — even though the work behind them shares nothing, so
     * this is the one place the two kinds are deliberately treated alike.
     */
    /**
     * Whether this kind is the provider half of a plan change.
     *
     * Two kinds, one message: a customer who upgraded does not care whether
     * the thing that changed was a hypervisor's idea of their memory or a
     * control panel's idea of their disk quota.
     */
    private function isAPlanChange(ProvisioningJobKind $kind): bool
    {
        return $kind === ProvisioningJobKind::Resize
            || $kind === ProvisioningJobKind::ChangeHostingPackage;
    }

    private function isAReinstall(ProvisioningJobKind $kind): bool
    {
        return $kind === ProvisioningJobKind::ReinstallVps
            || $kind === ProvisioningJobKind::ReinstallDedicated;
    }

    private function service(?string $serviceId): ?Service
    {
        return $serviceId === null ? null : Service::query()->find($serviceId);
    }

    /**
     * What to call the service in a sentence the customer reads.
     */
    private function label(Service $service): string
    {
        $label = $service->label;

        return is_string($label) && $label !== '' ? $label : (string) $service->getKey();
    }
}
