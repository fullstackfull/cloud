<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * Active services by product kind — what the platform is actually running for
 * customers right now.
 *
 * Counted from `services`, not from the providers. The hypervisor's opinion of
 * how many VMs exist is a different and equally useful number, and where the
 * two disagree the difference is drift: a machine still running for a service
 * that was terminated, or a service that is billed and has nothing behind it.
 * Reconciliation compares them; this metric is one side of that comparison and
 * must not quietly become the other.
 */
final readonly class ServicesCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'services';
    }

    public function collect(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('services')
            ->selectRaw('kind, count(*) as total')
            ->where('status', ServiceStatus::Active->value)
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $samples = [];

        foreach (ProductKind::cases() as $kind) {
            $samples[] = MetricSample::of(
                ['kind' => $kind->value],
                $counts[$kind->value] ?? 0,
            );

            unset($counts[$kind->value]);
        }

        /*
         * Anything left is a kind the catalogue enum does not know about —
         * a product added to the database ahead of the code, or a typo in a
         * seeder. It is emitted rather than dropped, because a service the
         * platform is running and not counting is exactly the kind of thing
         * that should be visible on a dashboard rather than invisible.
         */
        foreach ($counts as $kind => $total) {
            $samples[] = MetricSample::of(['kind' => (string) $kind], $total);
        }

        return [
            Metric::gauge(
                'lynomia_services_active',
                'Services in the active state, by product kind.',
                $samples,
            ),
        ];
    }
}
