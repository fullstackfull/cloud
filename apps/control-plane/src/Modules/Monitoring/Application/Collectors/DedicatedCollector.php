<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Lynomia\Modules\Dedicated\Application\Services\PowerClaimLease;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * What the platform has been asking dedicated servers' management controllers
 * to do to the power.
 *
 * Until this existed the power path — the one that physically cycles a
 * customer's machine — was on no dashboard and under no alert, beside a
 * dashboard called "dedicated" that gave no cue it was missing. Rebuilds were
 * already exported, by the console collector, under `kind="dedicated"`; power
 * requests were not exported by anything, and a request that ended
 * `indeterminate` — a reset the platform may have sent and cannot say whether
 * it landed — was recorded and watched by nobody.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Two families, zero-filled, with labels only from the two closed enums — at
 * most three actions by four outcomes. Nothing per server, customer, endpoint
 * or management address: a reset storm across a rack is exactly when a
 * per-machine label would mint a series per chassis, and a management address
 * in a label publishes the one thing the dedicated module is written to
 * withhold.
 *
 * Two queries, each a GROUP BY, whatever the number of rows.
 */
final readonly class DedicatedCollector implements MetricsCollector
{
    public function __construct(
        private PowerClaimLease $lease,
    ) {}

    public function name(): string
    {
        return 'dedicated';
    }

    public function collect(): array
    {
        return [
            $this->powerOperations(),
            $this->abandonedClaims(),
        ];
    }

    private function powerOperations(): Metric
    {
        $counts = [];

        $rows = DedicatedPowerOperation::query()
            ->toBase()
            ->selectRaw('action, outcome, count(*) as total')
            ->groupBy('action', 'outcome')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->action.'/'.$row->outcome] = (float) $row->total;
        }

        $samples = [];

        foreach (DedicatedPowerAction::cases() as $action) {
            foreach (PowerOperationOutcome::cases() as $outcome) {
                $samples[] = new MetricSample(
                    ['action' => $action->value, 'outcome' => $outcome->value],
                    $counts[$action->value.'/'.$outcome->value] ?? 0.0,
                );
            }
        }

        return Metric::gauge(
            'lynomia_dedicated_power_operation_total',
            'Dedicated power requests by action and outcome. indeterminate is a machine the platform may have powered or reset and cannot say whether it did: a controller that stopped answering, or a request whose worker died before it answered. Nothing retries either; somebody has to look at the machine.',
            $samples,
        );
    }

    /**
     * Claims past their lease that nothing has settled yet.
     *
     * The lease's own definition of "lapsed", so this counts exactly the rows
     * the sweep would settle on its next run.
     */
    private function abandonedClaims(): Metric
    {
        $counts = DedicatedPowerOperation::query()
            ->where('outcome', PowerOperationOutcome::Claimed->value)
            ->where('requested_at', '<=', $this->lease->lapsedAt())
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $samples = [];

        foreach (DedicatedPowerAction::cases() as $action) {
            $samples[] = new MetricSample(
                ['action' => $action->value],
                (float) ($counts[$action->value] ?? 0),
            );
        }

        return Metric::gauge(
            'lynomia_dedicated_power_claims_abandoned',
            'Dedicated power claims past their lease that the sweep has not settled yet, by action. The sweep runs every five minutes, so a number that stays above zero is one of two things this series cannot tell apart: the same old claims staying means the sweep is not running; different, recent claims each time mean workers are dying mid-call while the sweep keeps up.',
            $samples,
        );
    }
}
