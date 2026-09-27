<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Listeners;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Monitoring\Infrastructure\Models\ScheduledRun;
use Throwable;

/**
 * Writes down that a scheduled command ran, and whether it worked.
 *
 * Never queued, and never allowed to throw. This is the observability of the
 * scheduler, and a failure to record an outcome must not become a failure of
 * the run it was recording — a monitoring write that took down renewals would
 * be the worst possible trade.
 *
 * A skipped task is not a run. `withoutOverlapping` skips an entry whose
 * previous invocation is still going, and `onOneServer` skips it on every
 * host but one; counting either as a run would make a command that is
 * permanently stuck look perfectly healthy, because the skips keep arriving on
 * schedule. Skips are deliberately not recorded at all.
 *
 * One run is one outcome. What Laravel dispatches for a run, read from
 * ScheduleRunCommand::runEvent and ScheduleFinishCommand::handle:
 *
 *  - a foreground run that exits 0: ScheduledTaskFinished only;
 *  - a foreground run that exits non-zero (a command, or a closure that
 *    returns false): ScheduledTaskFinished with that exit code, and then,
 *    because schedule:run throws on it, ScheduledTaskFailed for the same run;
 *  - a closure that throws, or a before-callback that throws:
 *    ScheduledTaskFailed only;
 *  - a background run: ScheduledTaskFinished at launch, before any exit code,
 *    and later ScheduledBackgroundTaskFinished from schedule:finish, carrying
 *    the exit code.
 *
 * So a foreground failure is counted in failed() and nowhere else: finished()
 * with a non-zero exit code records nothing, because ScheduledTaskFailed for
 * the same run follows it. A background run is counted from its outcome, never
 * from its launch. Every entry in routes/console.php is a foreground command.
 * SchedulerLivenessTest drives a real schedule:run through each shape above.
 */
final class RecordScheduledRun
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskFinished::class => 'finished',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskSkipped::class => 'skipped',
        ];
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        /*
         * A background task has only been launched here; its exit code
         * arrives with ScheduledBackgroundTaskFinished. Recording the launch
         * as a success would make a command that fails every run look healthy.
         */
        if ($event->task->runInBackground) {
            return;
        }

        /*
         * A skip that arrives as "finished". withoutOverlapping's filter found
         * the mutex free, then Event::run failed to create it (another
         * invocation took it in between) and returned without running
         * anything. schedule:run still dispatches ScheduledTaskFinished, with
         * no exit code; recording that as a success is the skip the class
         * docblock says is never recorded.
         */
        if ($event->task->skippedBecauseOverlapping) {
            return;
        }

        /*
         * Laravel reports the exit code on the task itself. Zero is success.
         * Anything else is a run that failed, and schedule:run dispatches
         * ScheduledTaskFailed for it next, which is where it is counted:
         * counting it here as well counted every failed run twice.
         *
         * Null is taken as success, but a foreground run that got this far
         * never has it: Event::run sets the exit code in finish() for every
         * foreground run it does not skip, and one whose start throws
         * dispatches ScheduledTaskFailed instead of this event.
         */
        $exitCode = $event->task->exitCode;

        if ($exitCode !== 0 && $exitCode !== null) {
            return;
        }

        $this->succeeded($event->task, (int) round($event->runtime * 1000));
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        // A background run's outcome arrives in this event alone, and no
        // ScheduledTaskFailed follows it, so a non-zero exit is counted here.
        $exitCode = $event->task->exitCode;

        if ($exitCode === 0 || $exitCode === null) {
            // No runtime is reported with this event, so succeeded() leaves
            // last_runtime_ms holding whatever it held: a runtime measured by
            // an earlier foreground run of the same command, or null.
            // Writing null here would drop that command's runtime series.
            $this->succeeded($event->task, null);

            return;
        }

        $this->record($event->task->command ?? $event->task->description, static function (ScheduledRun $run) use ($exitCode): void {
            $run->last_ran_at = now();
            $run->last_failed_at = now();
            $run->consecutive_failures++;
            $run->last_failure = sprintf('exited with code %d', $exitCode);
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task->command ?? $event->task->description, static function (ScheduledRun $run) use ($event): void {
            $run->last_ran_at = now();
            $run->last_failed_at = now();
            $run->consecutive_failures++;
            // Truncated: this column is a signal, and the full trace is in the
            // application log where a person can read it properly. For a run
            // that exited non-zero this is Laravel's own sentence, which names
            // the exit code.
            $run->last_failure = mb_substr($event->exception->getMessage(), 0, 500);
        });
    }

    private function succeeded(ScheduledEvent $task, ?int $runtimeMs): void
    {
        $this->record($task->command ?? $task->description, static function (ScheduledRun $run) use ($runtimeMs): void {
            $run->last_ran_at = now();
            $run->last_succeeded_at = now();
            $run->consecutive_failures = 0;
            $run->last_failure = null;

            if ($runtimeMs !== null) {
                $run->last_runtime_ms = $runtimeMs;
            }
        });
    }

    /**
     * Deliberately does nothing. See the class docblock: a skip is not a run,
     * and recording one would make a permanently overlapping command look
     * healthy.
     */
    public function skipped(ScheduledTaskSkipped $event): void {}

    /**
     * @param  callable(ScheduledRun): void  $apply
     */
    private function record(?string $command, callable $apply): void
    {
        if ($command === null || $command === '') {
            return;
        }

        try {
            $run = ScheduledRun::query()->firstOrNew(['command' => $this->normalise($command)]);

            $apply($run);

            $run->save();
        } catch (Throwable $e) {
            // Logged and swallowed. The run itself has already happened, and
            // failing here would turn a monitoring problem into an outage.
            Log::warning('Could not record the outcome of a scheduled command.', [
                'command' => $command,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The command as a person would type it.
     *
     * Laravel hands over the full invocation — the PHP binary, the artisan
     * path, quoting and all — which differs between hosts and would make the
     * same command look like several. Reducing it to the artisan command name
     * is also what keeps this safe as a metric label: the set of values is the
     * set of entries in routes/console.php.
     */
    private function normalise(string $command): string
    {
        if (preg_match("/artisan'?\s+'?([a-z0-9:_-]+)/i", $command, $matches) === 1) {
            return $matches[1];
        }

        return mb_substr($command, 0, 191);
    }
}
