<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportOutcome;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * What the platform's zones and records are doing.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Every label value comes from an enum: seven states and six record types.
 * Nothing here is labelled by domain, zone, record value, customer or address,
 * and that is not only a cardinality decision — a domain name in a metric is a
 * customer's domain name in whatever monitoring system scrapes this, and in
 * every dashboard and alert built on top of it. A series per zone would also
 * grow without limit with the customer base, which is how a monitoring system
 * is taken down from inside.
 *
 * Every state is emitted, including the ones with no rows, so that a query for
 * `indeterminate` returns zero rather than nothing — an alert on a series that
 * does not exist is an alert that never fires.
 */
final readonly class DnsCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'dns';
    }

    public function collect(): array
    {
        return [
            Metric::gauge(
                'lynomia_dns_zone_total',
                'Zones the platform holds, by state. `indeterminate` is the one worth alerting on: it means a provider call did not answer and nobody knows whether the zone exists.',
                $this->byState('dns_zones'),
            ),
            Metric::gauge(
                'lynomia_dns_record_total',
                'Records the platform has published, by state. A rising `failed` count is customers typing values a provider will not take; a rising `indeterminate` count is the platform losing track.',
                $this->byState('dns_records'),
            ),
            Metric::gauge(
                'lynomia_dns_record_by_type_total',
                'Live records by type. Six values, from the enum: the platform publishes no others.',
                $this->byType(),
            ),
            Metric::gauge(
                'lynomia_dns_zone_imports_total',
                'Attempts to apply a zone file, by outcome. A rising `refused` is customers hitting a rule the preview should have explained; a rising `plan_changed` is previews going stale.',
                $this->imports(),
            ),
        ];
    }

    /**
     * @return list<MetricSample>
     */
    private function imports(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('dns_zone_imports')
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->all();

        $samples = [];

        foreach (ZoneImportOutcome::cases() as $outcome) {
            $samples[] = MetricSample::of(['outcome' => $outcome->value], (float) ($counts[$outcome->value] ?? 0));
        }

        return $samples;
    }

    /**
     * @return list<MetricSample>
     */
    private function byState(string $table): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table($table)
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();

        $samples = [];

        foreach (DnsState::cases() as $state) {
            $samples[] = MetricSample::of(
                ['state' => $state->value],
                (float) ($counts[$state->value] ?? 0),
            );
        }

        return $samples;
    }

    /**
     * @return list<MetricSample>
     */
    private function byType(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('dns_records')
            ->where('state', DnsState::Active->value)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $samples = [];

        foreach (DnsRecordType::cases() as $type) {
            $samples[] = MetricSample::of(
                ['type' => $type->value],
                (float) ($counts[$type->value] ?? 0),
            );
        }

        return $samples;
    }
}
