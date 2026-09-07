<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
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
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskSkipped::class => 'skipped',
        ];
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        /*
         * Laravel reports the exit code on the task itself. Zero is success;
         * anything else is a command that ran and refused, which for these
         * commands means the work did not happen.
         */
        $exitCode = $event->task->exitCode;

        if ($exitCode === 0 || $exitCode === null) {
            $this->record($event->task->command ?? $event->task->description, static function (ScheduledRun $run) use ($event): void {
                $run->last_ran_at = now();
                $run->last_succeeded_at = now();
                $run->consecutive_failures = 0;
                $run->last_failure = null;
                $run->last_runtime_ms = (int) round($event->runtime * 1000);
            });

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
            // application log where a person can read it properly.
            $run->last_failure = mb_substr($event->exception->getMessage(), 0, 500);
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
