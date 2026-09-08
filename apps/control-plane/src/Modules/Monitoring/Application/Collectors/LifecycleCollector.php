<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * The two lifecycle transitions a customer notices when they go wrong:
 * being switched off, and paying for something bigger.
 *
 * ---------------------------------------------------------------------------
 * Suspension, and the state that is nobody's plan
 * ---------------------------------------------------------------------------
 *
 * The suspension path is deliberately asymmetric — a service reaches `active`
 * again only once the hypervisor has confirmed the lock is gone, and stays in
 * `reactivating` when it will not — which means `reactivating` is not a busy
 * state, it is a customer who has paid and cannot use their server. A count
 * above zero for more than a minute is an outage nobody has been told about,
 * and until this collector existed it was invisible: `lynomia_services_active`
 * counts what is working and says nothing about what is stuck.
 *
 * Suspension operations themselves are not journalled in a table of their own,
 * and this does not pretend otherwise. What is countable is how many services
 * are suspended right now, and — in the drift series next door, under
 * `suspension_mismatch` — how many machines disagree with that. Between them
 * they answer "is enforcement working", which is the question.
 *
 * ---------------------------------------------------------------------------
 * Plan changes
 * ---------------------------------------------------------------------------
 *
 * The failure that matters here is one-sided: the money moved when the
 * customer confirmed, and the machine catches up afterwards through a resize
 * job. So a failed or reviewable resize is a customer who has been charged for
 * an upgrade they did not get, which is why it gets its own family under a
 * name an operator will search for rather than being left as one label
 * combination inside the general job counter.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Product kinds and two status enums: twenty-odd fixed series, whatever the
 * size of the fleet. Nothing is labelled by customer, service, subscription,
 * machine or hostname.
 */
final readonly class LifecycleCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'lifecycle';
    }

    public function collect(): array
    {
        return [
            $this->servicesByStatus(),
            $this->planChanges(),
        ];
    }

    private function servicesByStatus(): Metric
    {
        $rows = DB::table('services')
            ->selectRaw('kind, status, count(*) as total')
            ->groupBy('kind', 'status')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            /** @var object{kind: string, status: string, total: int|string} $row */
            $counts[$row->kind.'|'.$row->status] = (int) $row->total;
        }

        $samples = [];

        // The full cross product, zeros included: an alert rule for "a service
        // has been stuck reactivating" has to be writable before one is.
        foreach (ProductKind::cases() as $kind) {
            foreach (ServiceStatus::cases() as $status) {
                $samples[] = MetricSample::of(
                    ['kind' => $kind->value, 'status' => $status->value],
                    $counts[$kind->value.'|'.$status->value] ?? 0,
                );
            }
        }

        return Metric::gauge(
            'lynomia_service_status_total',
            'Services by product kind and status. `reactivating` is the one to alert on: a customer who has paid, whose machine the provider would not confirm, and who cannot use what they are paying for.',
            $samples,
        );
    }

    private function planChanges(): Metric
    {
        $rows = DB::table('provisioning_jobs')
            ->where('kind', ProvisioningJobKind::Resize->value)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            /** @var object{status: string, total: int|string} $row */
            $counts[$row->status] = (int) $row->total;
        }

        $samples = [];

        foreach (ProvisioningJobStatus::cases() as $status) {
            $samples[] = MetricSample::of(['status' => $status->value], $counts[$status->value] ?? 0);
        }

        return Metric::gauge(
            'lynomia_plan_change_total',
            'Plan changes by the state of the resize that carries them out. Anything but succeeded means a customer has been charged for a plan their machine has not been given.',
            $samples,
        );
    }
}
