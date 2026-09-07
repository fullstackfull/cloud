<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Lynomia\Modules\Console\Infrastructure\GatewayMetrics;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * What the console gateway and the rebuild machinery have been doing.
 *
 * The gateway runs in its own process, so its numbers cannot be counted from
 * the database the way every other metric here is. It writes counters to the
 * shared cache — the same Redis the permits live in — and this reads them back
 * when the API is scraped. A gateway that is not running therefore reports
 * zeros rather than nothing, which is the honest answer: no consoles were
 * opened.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Every label value comes from a fixed list — refusal reasons from
 * {@see GatewayMetrics::REASONS}, rebuild states from their enums. Nothing is
 * labelled by customer, machine, session, address or hostname. A refusal
 * reason in particular is one string away from being attacker-controlled, and
 * a series labelled with attacker-controlled strings is a way to take a
 * monitoring system down from outside the platform.
 */
final readonly class ConsoleCollector implements MetricsCollector
{
    public function __construct(
        private GatewayMetrics $metrics,
    ) {}

    public function name(): string
    {
        return 'console';
    }

    public function collect(): array
    {
        return [
            $this->connections(),
            $this->refusals(),
            $this->reinstalls(),
        ];
    }

    private function connections(): Metric
    {
        $samples = [];

        foreach (GatewayMetrics::OUTCOMES as $outcome) {
            $samples[] = new MetricSample(['outcome' => $outcome], (float) $this->metrics->read($outcome));
        }

        return Metric::gauge(
            'lynomia_console_connection_total',
            'Console gateway connections by outcome. Accepted counts sockets, opened counts consoles that reached a hypervisor: a gap between them is customers pressing the button and getting nothing.',
            $samples,
        );
    }

    private function refusals(): Metric
    {
        $samples = [];

        foreach (GatewayMetrics::REASONS as $reason) {
            $samples[] = new MetricSample(['reason' => $reason], (float) $this->metrics->read('refused:'.$reason));
        }

        return Metric::gauge(
            'lynomia_console_refusal_total',
            'Console permits refused, by reason. A rising machine_mismatch or invalid_permit count from one source is somebody trying permits that are not theirs.',
            $samples,
        );
    }

    /**
     * Rebuilds by state.
     *
     * Here rather than in the provisioning collector because the question is
     * not "did a job run" but "how many customers' disks are in an unknown
     * state right now" — which is the number an operator wants at three in the
     * morning, and which the job status cannot answer.
     */
    private function reinstalls(): Metric
    {
        $counts = VmReinstall::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $samples = [];

        foreach (ReinstallState::cases() as $state) {
            $samples[] = new MetricSample(
                ['kind' => 'vps', 'state' => $state->value],
                (float) ($counts[$state->value] ?? 0),
            );
        }

        $dedicated = DedicatedReinstall::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        foreach (DedicatedReinstallState::cases() as $state) {
            $samples[] = new MetricSample(
                ['kind' => 'dedicated', 'state' => $state->value],
                (float) ($dedicated[$state->value] ?? 0),
            );
        }

        return Metric::gauge(
            'lynomia_reinstall_operation_total',
            'Rebuilds by kind and state. needs_review and indeterminate are the ones that matter: each is a machine whose disks may be gone and which nothing automatic will touch again.',
            $samples,
        );
    }
}
