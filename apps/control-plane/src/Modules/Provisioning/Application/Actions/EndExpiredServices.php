<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Support\Lifecycle\EndOfService;
use Throwable;

/**
 * Finishes what a cancellation started, once the retention window has closed.
 *
 * ---------------------------------------------------------------------------
 * Only what a customer asked to end
 * ---------------------------------------------------------------------------
 *
 * A service suspended for non-payment is never ended here, whatever
 * `provisioning.termination.sweep_cancelled` says. The two look identical in
 * the database — suspended, window elapsed — and they are completely different
 * decisions. A customer who cancelled chose the date and was told it twice; a
 * customer who has not paid is somebody the business may still want back, and
 * destroying their data thirty days into a billing dispute is a decision that
 * needs a person's name on it. That person has the operator endpoint.
 *
 * ---------------------------------------------------------------------------
 * The warning
 * ---------------------------------------------------------------------------
 *
 * A few days before the window closes the customer is told, once. Thirty days
 * is long enough to forget a cancellation made in a hurry, and "your data is
 * destroyed on Friday" is the message that saves somebody's business. The stamp
 * in `retention_warned_at` is what makes "once" true.
 *
 * ---------------------------------------------------------------------------
 * One failure does not stop the sweep
 * ---------------------------------------------------------------------------
 *
 * Each service is attempted on its own and a failure is logged and stepped
 * over. A control panel that is down must not mean every other departing
 * customer's service stays half-ended — and the row is untouched, so the next
 * run tries it again.
 */
final readonly class EndExpiredServices
{
    public function __construct(
        private EndOfService $ending,
        private NotifyCustomer $notify,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @return array{ended: int, warned: int, failed: int}
     */
    public function execute(): array
    {
        $now = CarbonImmutable::now();

        $warned = $this->warn($now);

        if (config('provisioning.termination.sweep_cancelled', true) !== true) {
            return ['ended' => 0, 'warned' => $warned, 'failed' => 0];
        }

        $ended = 0;
        $failed = 0;

        foreach ($this->outOfTime($now) as $service) {
            try {
                $detail = $this->ending->execute($service);
            } catch (Throwable $e) {
                $failed++;

                Log::warning('A service whose retention window has closed could not be ended.', [
                    'service_id' => (string) $service->getKey(),
                    'kind' => $service->kind,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $ended++;

            /*
             * Audited as the platform acting rather than a person, and the
             * entry says which decision it was carrying out. "Who terminated
             * this service" must never answer "nobody knows".
             */
            $this->audit->execute(
                action: AuditAction::ServiceTerminated,
                subject: $service,
                customerId: (string) $service->customer_id,
                context: [
                    'kind' => $service->kind,
                    'ended_reason' => $service->ended_reason,
                    'retention_ended_at' => $service->retention_ends_at?->toIso8601String(),
                    'detail' => $detail,
                    'terminated_by' => 'retention sweep',
                ],
            );

            $this->notify->execute(
                customerId: (string) $service->customer_id,
                type: NotificationType::ServiceTerminated,
                idempotencyKey: 'service-ended:'.$service->getKey(),
                subject: $service,
                data: [
                    'service' => $service->label ?? 'your service',
                    'date' => $now->toDateString(),
                ],
                link: '/services',
            );
        }

        return ['ended' => $ended, 'warned' => $warned, 'failed' => $failed];
    }

    /**
     * Tell the customer, once, before anything is destroyed.
     */
    private function warn(CarbonImmutable $now): int
    {
        $days = max(0, (int) config('provisioning.termination.warn_days_before', 3));
        $warned = 0;

        $due = Service::query()
            ->where('status', ServiceStatus::Suspended->value)
            ->whereNotNull('retention_ends_at')
            ->whereNull('retention_warned_at')
            ->where('retention_ends_at', '<=', $now->addDays($days))
            // Already past it: the ending itself is the message, and a warning
            // that arrives with the deletion is worse than none.
            ->where('retention_ends_at', '>', $now)
            ->limit(500)
            ->get();

        foreach ($due as $service) {
            $this->notify->execute(
                customerId: (string) $service->customer_id,
                type: NotificationType::DataRetentionEnding,
                idempotencyKey: 'retention-warning:'.$service->getKey(),
                subject: $service,
                data: [
                    'service' => $service->label ?? 'your service',
                    'date' => $service->retention_ends_at?->toDateString() ?? '',
                ],
                link: '/services',
            );

            $service->forceFill(['retention_warned_at' => $now])->save();
            $warned++;
        }

        return $warned;
    }

    /**
     * @return list<Service>
     */
    private function outOfTime(CarbonImmutable $now): array
    {
        /** @var list<Service> $services */
        $services = Service::query()
            ->where('status', ServiceStatus::Suspended->value)
            ->whereNotNull('retention_ends_at')
            ->where('retention_ends_at', '<=', $now)
            // The whole of the automation boundary, in one clause.
            ->where('ended_reason', BeginRetentionWindow::BY_CUSTOMER)
            ->limit(200)
            ->get()
            ->all();

        return $services;
    }
}
