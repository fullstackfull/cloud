<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Monitoring\Application\Collectors\SchedulerCollector;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Monitoring\Infrastructure\Models\ScheduledRun;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Whether the platform can tell that its scheduler has stopped.
 *
 * Everything that keeps an account correct after the moment somebody buys runs
 * unattended: renewals, dunning, drift detection, address reclamation, payment
 * reconciliation. The failure that costs most is not a command that errors —
 * it is a command that is never invoked. A crashed scheduler, a cron entry
 * lost in a redeploy, a container that came back without its supervisor:
 * nothing errors, no alert fires, and the first symptom is a customer whose
 * subscription was never renewed.
 */
final class SchedulerLivenessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_successful_run_is_recorded_against_the_command(): void
    {
        event(new ScheduledTaskFinished($this->task('subscriptions:renew'), 1.25));

        $run = ScheduledRun::query()->sole();

        // Normalised to what a person would type. Laravel hands over the whole
        // invocation — binary, artisan path, quoting — which differs between
        // hosts and would make one command look like several.
        $this->assertSame('subscriptions:renew', $run->command);
        $this->assertNotNull($run->last_succeeded_at);
        $this->assertSame(0, $run->consecutive_failures);
        $this->assertSame(1250, $run->last_runtime_ms);
    }

    #[Test]
    public function a_failure_is_counted_and_does_not_move_the_last_success(): void
    {
        /*
         * The distinction the whole table exists for. A command that runs
         * every five minutes and fails every time keeps its last_ran_at
         * perfectly fresh, and an alert built on that stays green through a
         * total outage of the thing it is watching.
         */
        event(new ScheduledTaskFinished($this->task('subscriptions:renew'), 1.0));

        $succeededAt = ScheduledRun::query()->sole()->last_succeeded_at;

        event(new ScheduledTaskFailed(
            $this->task('subscriptions:renew'),
            new RuntimeException('the payment provider refused every renewal'),
        ));
        event(new ScheduledTaskFailed(
            $this->task('subscriptions:renew'),
            new RuntimeException('again'),
        ));

        $run = ScheduledRun::query()->sole();

        $this->assertEquals($succeededAt, $run->last_succeeded_at);
        $this->assertSame(2, $run->consecutive_failures);
        // The newest failure's words, not the first one's: an operator
        // reading this wants to know why it is failing now.
        $this->assertSame('again', $run->last_failure);

        // A success clears the streak, so the metric distinguishes a flap from
        // an outage.
        event(new ScheduledTaskFinished($this->task('subscriptions:renew'), 1.0));

        $this->assertSame(0, ScheduledRun::query()->sole()->consecutive_failures);
    }

    #[Test]
    public function a_non_zero_exit_code_is_a_failure_even_though_the_task_finished(): void
    {
        // "Finished" means the process ended, not that the work happened.
        $task = $this->task('ipam:reclaim');
        $task->exitCode = 1;

        event(new ScheduledTaskFinished($task, 0.5));

        $run = ScheduledRun::query()->sole();

        $this->assertNull($run->last_succeeded_at);
        $this->assertSame(1, $run->consecutive_failures);
        $this->assertNotNull($run->last_ran_at);
    }

    #[Test]
    public function a_skipped_task_is_not_a_run(): void
    {
        /*
         * withoutOverlapping skips an entry whose previous invocation is still
         * going, and onOneServer skips it on every host but one. Counting
         * either as a run makes a command that is permanently stuck look
         * perfectly healthy, because the skips keep arriving on schedule.
         */
        event(new ScheduledTaskSkipped($this->task('infrastructure:reconcile')));

        $this->assertSame(0, ScheduledRun::query()->count());
    }

    #[Test]
    public function a_command_that_has_never_succeeded_publishes_no_timestamp(): void
    {
        /*
         * Not zero. Zero is 1970, which every "older than" alert fires on
         * immediately — including on a fresh deployment where nothing has run
         * yet, which is how a monitoring system teaches people to ignore it in
         * its first hour.
         */
        event(new ScheduledTaskFailed($this->task('payments:reconcile'), new RuntimeException('nope')));

        $exposition = $this->scrape();

        $this->assertStringNotContainsString(
            'lynomia_scheduled_command_last_success_timestamp_seconds{command="payments:reconcile"}',
            $exposition,
        );

        // The failure count is published, though: that is how a command that
        // has never once worked becomes visible.
        $this->assertStringContainsString(
            'lynomia_scheduled_command_consecutive_failures{command="payments:reconcile"} 1',
            $exposition,
        );
    }

    #[Test]
    public function the_exposition_carries_the_timestamp_an_alert_reads(): void
    {
        event(new ScheduledTaskFinished($this->task('subscriptions:renew'), 2.0));

        $exposition = $this->scrape();

        $this->assertStringContainsString(
            'lynomia_scheduled_command_last_success_timestamp_seconds{command="subscriptions:renew"}',
            $exposition,
        );
        $this->assertStringContainsString(
            'lynomia_scheduled_command_last_runtime_seconds{command="subscriptions:renew"} 2',
            $exposition,
        );
    }

    #[Test]
    public function nothing_is_labelled_by_anything_unbounded(): void
    {
        /*
         * One label, and its values are the entries in routes/console.php. A
         * series per customer, service or host is how a Prometheus falls over,
         * and this endpoint is scraped every fifteen seconds for ever.
         */
        event(new ScheduledTaskFinished($this->task('subscriptions:renew'), 1.0));

        foreach (app(SchedulerCollector::class)->collect() as $metric) {
            foreach ($metric->samples as $sample) {
                $this->assertSame(['command'], array_keys($sample->labels));
            }
        }
    }

    private function scrape(): string
    {
        return app(PrometheusTextFormatter::class)->render(app(SchedulerCollector::class)->collect());
    }

    private function task(string $command): ScheduledEvent
    {
        $task = app(Schedule::class)->command($command);

        // As the scheduler really reports it: the binary, the artisan path and
        // the quoting a host happens to produce.
        $task->command = "'/usr/bin/php8.4' 'artisan' ".$command;
        $task->exitCode = 0;

        return $task;
    }
}
