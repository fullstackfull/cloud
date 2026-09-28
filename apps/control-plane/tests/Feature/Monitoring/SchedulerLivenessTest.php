<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Closure;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Monitoring\Application\Collectors\SchedulerCollector;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Monitoring\Infrastructure\Models\ScheduledRun;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
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
 *
 * Every outcome here comes from Laravel's own `schedule:run` (and, for a
 * background task, its own `schedule:finish`), not from events this test
 * dispatches by hand. An earlier version of this file dispatched
 * ScheduledTaskFinished alone for a failure; Laravel dispatches it and then
 * ScheduledTaskFailed for the same run, and the listener counted both, so the
 * test was green while every failed run was counted twice.
 */
final class SchedulerLivenessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_failed_run_of_a_command_is_one_failure_and_two_are_two(): void
    {
        /*
         * A command that exits non-zero in the foreground — the only kind of
         * entry routes/console.php has. schedule:run dispatches
         * ScheduledTaskFinished with the exit code, and then throws and
         * dispatches ScheduledTaskFailed for the same run.
         */
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('false'));

        $this->runTheScheduler();

        $run = ScheduledRun::query()->sole();
        $this->assertSame('false', $run->command);
        $this->assertSame(1, $run->consecutive_failures);
        $this->assertNull($run->last_succeeded_at);
        $this->assertNotNull($run->last_failed_at);
        $this->assertStringContainsString('exit code [1]', (string) $run->last_failure);

        $this->runTheScheduler();

        $this->assertSame(2, ScheduledRun::query()->sole()->consecutive_failures);
    }

    #[Test]
    public function a_failure_is_counted_and_does_not_move_the_last_success_and_a_success_resets_it(): void
    {
        /*
         * The distinction the whole table exists for. A command that runs
         * every five minutes and fails every time keeps its last_ran_at
         * perfectly fresh, and an alert built on that stays green through a
         * total outage of the thing it is watching.
         *
         * One command string, so one row: it fails while the flag file exists.
         */
        $flag = tempnam(sys_get_temp_dir(), 'sched-flag-');
        unlink($flag);
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('test ! -e '.escapeshellarg($flag)));

        try {
            $this->runTheScheduler();
            $succeededAt = ScheduledRun::query()->sole()->last_succeeded_at;
            $this->assertNotNull($succeededAt);
            $this->assertSame(0, ScheduledRun::query()->sole()->consecutive_failures);

            touch($flag);
            $this->travel(1)->minutes();
            $this->runTheScheduler();
            $this->travel(1)->minutes();
            $this->runTheScheduler();

            $run = ScheduledRun::query()->sole();
            $this->assertEquals($succeededAt, $run->last_succeeded_at);
            $this->assertSame(2, $run->consecutive_failures);
            $this->assertNotNull($run->last_failure);

            // A success clears the streak, so the metric distinguishes a flap
            // from an outage.
            unlink($flag);
            $this->runTheScheduler();

            $run = ScheduledRun::query()->sole();
            $this->assertSame(0, $run->consecutive_failures);
            $this->assertNull($run->last_failure);
        } finally {
            @unlink($flag);
        }
    }

    #[Test]
    public function a_closure_that_throws_is_one_failure_per_run_with_its_own_words(): void
    {
        // Laravel dispatches only ScheduledTaskFailed for this one.
        $this->scheduleOnly(fn (Schedule $s) => $s
            ->call(static function (): void {
                throw new RuntimeException('the payment provider refused every renewal');
            })
            ->name('closure-throws'));

        $this->runTheScheduler();

        $run = ScheduledRun::query()->sole();
        $this->assertSame('closure-throws', $run->command);
        $this->assertSame(1, $run->consecutive_failures);
        $this->assertSame('the payment provider refused every renewal', $run->last_failure);

        $this->runTheScheduler();

        $this->assertSame(2, ScheduledRun::query()->sole()->consecutive_failures);
    }

    #[Test]
    public function a_closure_that_returns_false_is_one_failure_per_run(): void
    {
        // Exit code 1 without an exception: Finished, then Failed, like a command.
        $this->scheduleOnly(fn (Schedule $s) => $s->call(static fn (): bool => false)->name('closure-refuses'));

        $this->runTheScheduler();

        $this->assertSame(1, ScheduledRun::query()->sole()->consecutive_failures);

        $this->runTheScheduler();

        $this->assertSame(2, ScheduledRun::query()->sole()->consecutive_failures);
    }

    #[Test]
    public function a_background_run_is_counted_by_its_outcome_and_not_by_its_launch(): void
    {
        /*
         * runInBackground: schedule:run starts the process and dispatches
         * ScheduledTaskFinished with no exit code yet; the outcome arrives
         * later, when the backgrounded shell calls schedule:finish, as
         * ScheduledBackgroundTaskFinished. The launch is not a success.
         *
         * `true` is what is launched, so the backgrounded shell succeeds and
         * its own schedule:finish (in a separate process, which does not
         * define this entry) changes nothing. The outcome this test counts is
         * the one it hands schedule:finish itself.
         */
        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('true')->runInBackground());

        $this->runTheScheduler();

        $this->assertSame(0, ScheduledRun::query()->count(), 'a launch is not an outcome');

        Artisan::call('schedule:finish', ['id' => $event->mutexName(), 'code' => 1]);

        $run = ScheduledRun::query()->sole();
        $this->assertSame(1, $run->consecutive_failures);
        $this->assertNull($run->last_succeeded_at);
        $this->assertSame('exited with code 1', $run->last_failure);

        Artisan::call('schedule:finish', ['id' => $event->mutexName(), 'code' => 1]);
        $this->assertSame(2, ScheduledRun::query()->sole()->consecutive_failures);

        Artisan::call('schedule:finish', ['id' => $event->mutexName(), 'code' => 0]);
        $run = ScheduledRun::query()->sole();
        $this->assertSame(0, $run->consecutive_failures);
        $this->assertNotNull($run->last_succeeded_at);
    }

    #[Test]
    public function a_background_success_keeps_the_runtime_a_foreground_run_measured(): void
    {
        // The outcome of a background run carries no runtime. It must not
        // erase the one the same command's last foreground run recorded.
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('true'));
        $this->runTheScheduler();
        $measured = ScheduledRun::query()->sole()->last_runtime_ms;
        $this->assertNotNull($measured);

        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('true')->runInBackground());
        Artisan::call('schedule:finish', ['id' => $event->mutexName(), 'code' => 0]);

        $run = ScheduledRun::query()->sole();
        $this->assertNotNull($run->last_succeeded_at);
        $this->assertSame($measured, $run->last_runtime_ms);
        $this->assertStringContainsString('lynomia_scheduled_command_last_runtime_seconds{command="true"}', $this->scrape());
    }

    #[Test]
    public function a_successful_run_is_recorded_against_the_command_as_a_person_would_type_it(): void
    {
        /*
         * Normalised to what a person would type. Laravel hands over the whole
         * invocation — binary, artisan path, quoting — which differs between
         * hosts and would make one command look like several. `list` is run
         * for real, in a child process, because it reads nothing and writes
         * nothing.
         */
        $this->scheduleOnly(fn (Schedule $s) => $s->command('list'));

        $this->runTheScheduler();

        $run = ScheduledRun::query()->sole();
        $this->assertSame('list', $run->command);
        $this->assertNotNull($run->last_succeeded_at);
        $this->assertSame(0, $run->consecutive_failures);
        $this->assertNotNull($run->last_runtime_ms);
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
        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('true')->withoutOverlapping(60));

        // A previous invocation still running holds the mutex.
        $this->assertTrue($event->mutex->create($event));

        $this->runTheScheduler();

        $this->assertSame(0, ScheduledRun::query()->count());
    }

    #[Test]
    public function a_run_that_loses_the_overlap_lock_after_its_filter_passed_is_not_a_run(): void
    {
        /*
         * The other way withoutOverlapping skips. Its filter asks whether the
         * mutex exists and finds it free; Event::run then fails to create it,
         * because another invocation took it in between, and returns without
         * running anything and with no exit code. schedule:run dispatches
         * ScheduledTaskFinished for it all the same. A mutex that says it is
         * free and refuses every create is that race, every time.
         */
        $refusing = new class implements EventMutex
        {
            public function create(ScheduledEvent $event): bool
            {
                return false;
            }

            public function exists(ScheduledEvent $event): bool
            {
                return false;
            }

            public function forget(ScheduledEvent $event): void {}
        };

        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('false')->withoutOverlapping(60));
        $event->preventOverlapsUsing($refusing);

        $this->runTheScheduler();

        $this->assertTrue($event->skippedBecauseOverlapping, 'The race this test is about did not happen.');
        $this->assertSame(0, ScheduledRun::query()->count(), 'A run that never started was recorded.');
    }

    #[Test]
    public function a_repetition_skipped_after_a_failed_one_is_not_another_failure(): void
    {
        /*
         * A sub-minute entry repeats inside one schedule:run, on one event
         * object. The exit code of a failed repetition stays on it, so a later
         * repetition that loses the overlap race returns without running and
         * schedule:run still throws "failed with exit code [1]" for it and
         * dispatches ScheduledTaskFailed. One run and five skips were counted
         * as six failures.
         */
        $mutex = $this->mutexThatGrantsOnlyTheFirstRun();
        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('false'))->everyFiveSeconds()->withoutOverlapping(60);
        $event->preventOverlapsUsing($mutex);

        $this->runTheSchedulerForTheRestOfTheMinute();

        $this->assertGreaterThan(1, $mutex->creates, 'The entry did not repeat, so nothing was skipped.');
        $this->assertTrue($event->skippedBecauseOverlapping, 'The race this test is about did not happen.');
        $run = ScheduledRun::query()->sole();
        $this->assertSame(1, $run->consecutive_failures, 'An overlap skip was counted as a failed run.');
    }

    #[Test]
    public function a_named_closure_skipped_after_it_threw_is_not_another_failure(): void
    {
        // The other way: CallbackEvent keeps the exception its callback threw
        // and rethrows it after a run that was skipped.
        $mutex = $this->mutexThatGrantsOnlyTheFirstRun();
        $event = $this->scheduleOnly(fn (Schedule $s) => $s
            ->call(static function (): void {
                throw new RuntimeException('the registrar refused the renewal');
            })
            ->name('closure-throws-once'))
            ->everyFiveSeconds()
            ->withoutOverlapping(60);
        $event->preventOverlapsUsing($mutex);

        $this->runTheSchedulerForTheRestOfTheMinute();

        $this->assertGreaterThan(1, $mutex->creates, 'The entry did not repeat, so nothing was skipped.');
        $this->assertTrue($event->skippedBecauseOverlapping, 'The race this test is about did not happen.');
        $run = ScheduledRun::query()->sole();
        $this->assertSame(1, $run->consecutive_failures, 'A skip rethrowing an old exception was counted as a failed run.');
        $this->assertSame('the registrar refused the renewal', $run->last_failure);
    }

    #[Test]
    public function every_repetition_that_runs_and_fails_is_counted(): void
    {
        // The guard must not hide real failures: nothing is skipped here, and
        // each repetition is one failure.
        $mutex = $this->mutexThatGrantsOnlyTheFirstRun(grantEvery: true);
        $event = $this->scheduleOnly(fn (Schedule $s) => $s->exec('false'))->everyFiveSeconds()->withoutOverlapping(60);
        $event->preventOverlapsUsing($mutex);

        $this->runTheSchedulerForTheRestOfTheMinute();

        $this->assertGreaterThan(1, $mutex->creates);
        $this->assertFalse($event->skippedBecauseOverlapping);
        $this->assertSame($mutex->creates, ScheduledRun::query()->sole()->consecutive_failures);
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
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('false'));

        $this->runTheScheduler();

        $exposition = $this->scrape();

        $this->assertStringNotContainsString(
            'lynomia_scheduled_command_last_success_timestamp_seconds{command="false"}',
            $exposition,
        );

        // The failure count is published, though: that is how a command that
        // has never once worked becomes visible. One run, one failure.
        $this->assertStringContainsString(
            'lynomia_scheduled_command_consecutive_failures{command="false"} 1'."\n",
            $exposition,
        );
    }

    #[Test]
    public function the_exposition_carries_the_timestamp_an_alert_reads(): void
    {
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('true'));

        $this->runTheScheduler();

        $exposition = $this->scrape();

        $this->assertStringContainsString(
            'lynomia_scheduled_command_last_success_timestamp_seconds{command="true"}',
            $exposition,
        );
        $this->assertStringContainsString(
            'lynomia_scheduled_command_last_runtime_seconds{command="true"}',
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
        $this->scheduleOnly(fn (Schedule $s) => $s->exec('true'));

        $this->runTheScheduler();

        foreach (app(SchedulerCollector::class)->collect() as $metric) {
            foreach ($metric->samples as $sample) {
                $this->assertSame(['command'], array_keys($sample->labels));
            }
        }
    }

    /**
     * Replaces the application's schedule with the one entry a test defines,
     * due every minute, so schedule:run runs exactly that.
     *
     * @param  Closure(Schedule): ScheduledEvent  $define
     */
    private function scheduleOnly(Closure $define): ScheduledEvent
    {
        $schedule = app(Schedule::class);

        (function (): void {
            $this->events = [];
        })->call($schedule);

        return $define($schedule)->everyMinute();
    }

    private function runTheScheduler(): void
    {
        Artisan::call('schedule:run');
    }

    /**
     * schedule:run with its sub-minute loop, which lasts until the end of the
     * minute it started in. The clock is put at second 30 and Sleep is faked
     * in step with it, so the loop's six repetitions take no real time. The
     * command is built here because it reads the clock when constructed.
     */
    private function runTheSchedulerForTheRestOfTheMinute(): void
    {
        $this->travelTo(now()->startOfMinute()->addSeconds(30));
        Sleep::fake(syncWithCarbon: true);

        $command = $this->app->make(ScheduleRunCommand::class);
        $command->setLaravel($this->app);
        $command->run(new ArrayInput([]), new NullOutput);
    }

    /**
     * A mutex that grants the first create and refuses every later one while
     * saying it is free: each later repetition passes its filter and then
     * loses the race in Event::run. With $grantEvery it grants every create.
     * Its public $creates counts the attempts.
     */
    private function mutexThatGrantsOnlyTheFirstRun(bool $grantEvery = false): EventMutex
    {
        return new class($grantEvery) implements EventMutex
        {
            public int $creates = 0;

            public function __construct(private readonly bool $grantEvery) {}

            public function create(ScheduledEvent $event): bool
            {
                return ++$this->creates === 1 || $this->grantEvery;
            }

            public function exists(ScheduledEvent $event): bool
            {
                return false;
            }

            public function forget(ScheduledEvent $event): void {}
        };
    }

    private function scrape(): string
    {
        return app(PrometheusTextFormatter::class)->render(app(SchedulerCollector::class)->collect());
    }
}
