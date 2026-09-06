<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;

/**
 * Orders by state.
 *
 * One query, a GROUP BY on an indexed column. The obvious alternative —
 * counting `Order::all()` in PHP — is the mistake this whole module is designed
 * to avoid: it works on a laptop with forty rows and hydrates a hundred
 * thousand Eloquent models every fifteen seconds in production.
 *
 * Exposed as a gauge despite the `_total` name the platform's alerting contract
 * requires. It is a count of rows in each state, and a row moves between
 * states: `queued_for_provisioning` goes *down* when a worker picks the order
 * up. Only the terminal states are monotonic, which is why every rule that
 * measures change over this series uses delta() rather than rate().
 */
final readonly class OrdersCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'orders';
    }

    public function collect(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('orders')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $samples = [];

        /*
         * Every status, including the ones with no rows. An absent series
         * cannot be alerted on: "orders in manual review > 0" never fires when
         * the series does not exist, so a platform with a broken collector and
         * a platform with nothing to review look identical.
         */
        foreach (OrderStatus::cases() as $status) {
            $samples[] = MetricSample::of(
                ['status' => $status->value],
                $counts[$status->value] ?? 0,
            );
        }

        return [
            Metric::gauge(
                'lynomia_orders_total',
                'Orders by status. A state count, not a monotonic counter: use delta() over terminal states.',
                $samples,
            ),
        ];
    }
}
