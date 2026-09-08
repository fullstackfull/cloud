<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Monitoring\Infrastructure\Models\ScheduledRun;

/**
 * Whether the scheduler is still running the platform's unattended work.
 *
 * Everything that keeps a customer's account correct after the moment they buy
 * runs here: renewals, dunning, drift detection, address reclamation, payment
 * reconciliation, hardware inventory. All of it is invisible when it works,
 * and — until this collector — invisible when it stops.
 *
 * The failure being watched for is not a command that errors. It is a command
 * that is never invoked: a crashed scheduler, a cron entry lost in a redeploy,
 * a container that came back without its supervisor. Nothing errors, no alert
 * fires, and the first symptom is a customer whose subscription was never
 * renewed.
 *
 * ---------------------------------------------------------------------------
 * The metric to alert on is the age, not the count
 * ---------------------------------------------------------------------------
 *
 * `last_success_timestamp_seconds` is published rather than an "is it healthy"
 * boolean, because the platform cannot know each command's acceptable staleness
 * and the alert can: `time() - lynomia_scheduled_command_last_success_timestamp_seconds > 900`
 * is a rule an operator writes per command, against the actual schedule.
 *
 * A command that has never succeeded has no sample at all rather than a zero.
 * Zero is 1970, which every "older than" alert fires on immediately — including
 * on a fresh deployment where nothing has run yet, which is how a monitoring
 * system teaches people to ignore it in its first hour.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * One label, `command`, and its values are the entries in routes/console.php —
 * a list of eight or nine that changes when somebody edits that file. Nothing
 * here is labelled by customer, service, job or host, because a metrics series
 * per customer is how a Prometheus falls over, and this endpoint is scraped
 * every fifteen seconds for ever.
 */
final readonly class SchedulerCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'scheduler';
    }

    public function collect(): array
    {
        $runs = ScheduledRun::query()->orderBy('command')->get();

        return [
            $this->lastSuccess($runs->all()),
            $this->consecutiveFailures($runs->all()),
            $this->runtime($runs->all()),
        ];
    }

    /**
     * @param  list<ScheduledRun>  $runs
     */
    private function lastSuccess(array $runs): Metric
    {
        $samples = [];

        foreach ($runs as $run) {
            if ($run->last_succeeded_at === null) {
                // No sample rather than zero. See the class docblock.
                continue;
            }

            $samples[] = new MetricSample(
                ['command' => $run->command],
                (float) $run->last_succeeded_at->getTimestamp(),
            );
        }

        return Metric::gauge(
            'lynomia_scheduled_command_last_success_timestamp_seconds',
            'Unix time of the last successful run of each scheduled command. Alert on its age: a command that stops being invoked at all is silent, and that is the failure worth catching.',
            $samples,
        );
    }

    /**
     * @param  list<ScheduledRun>  $runs
     */
    private function consecutiveFailures(array $runs): Metric
    {
        $samples = [];

        foreach ($runs as $run) {
            $samples[] = new MetricSample(
                ['command' => $run->command],
                (float) $run->consecutive_failures,
            );
        }

        return Metric::gauge(
            'lynomia_scheduled_command_consecutive_failures',
            'How many times in a row each scheduled command has failed. Reset to zero by a success, so this distinguishes a flap from an outage.',
            $samples,
        );
    }

    /**
     * @param  list<ScheduledRun>  $runs
     */
    private function runtime(array $runs): Metric
    {
        $samples = [];

        foreach ($runs as $run) {
            if ($run->last_runtime_ms === null) {
                continue;
            }

            /*
             * The last run's duration, not a histogram. What this is for is
             * noticing that a sweep which took two seconds now takes four
             * minutes — the shape of a command about to start overlapping
             * itself and being skipped for ever after.
             */
            $samples[] = new MetricSample(
                ['command' => $run->command],
                $run->last_runtime_ms / 1000,
            );
        }

        return Metric::gauge(
            'lynomia_scheduled_command_last_runtime_seconds',
            'How long each scheduled command took on its last successful run. A sweep growing towards its own interval is one about to be skipped for ever.',
            $samples,
        );
    }
}
