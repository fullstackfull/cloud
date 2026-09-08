<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deployment installs a `schedule:run` cron. This is the test that it has
 * something to run.
 *
 * For the whole of this build it did not. Renewals, cancellations, dunning and
 * backup reconciliation were all written and tested, the cron fired every
 * minute, and the schedule was empty — so subscriptions never renewed,
 * cancellations never took effect, suspended services were never terminated and
 * a backup never left "running". Nothing failed; nothing happened at all, which
 * is the harder failure to notice.
 *
 * Asserting on the schedule rather than on the existence of the commands is
 * deliberate: a command nothing calls is exactly the state this platform was
 * already in.
 */
final class ScheduledWorkIsRegisteredTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(array_map(
            static fn (Event $event): ?string => is_string($event->command)
                ? self::artisanCommandIn($event->command)
                : null,
            $schedule->events(),
        )));
    }

    /**
     * The scheduled command line is a full invocation — a PHP binary, the
     * artisan path, then the command. Only the last part identifies the work.
     */
    private static function artisanCommandIn(string $commandLine): ?string
    {
        if (preg_match('/artisan\S*.\s+\'?([a-z0-9:_-]+)\'?/i', $commandLine, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    #[Test]
    public function the_business_clock_is_scheduled(): void
    {
        $scheduled = $this->scheduledCommands();

        foreach (['subscriptions:renew', 'subscriptions:sweep', 'backups:reconcile'] as $command) {
            $this->assertContains(
                $command,
                $scheduled,
                sprintf('%s is not scheduled, so nothing will ever run it.', $command),
            );
        }
    }

    #[Test]
    public function every_scheduled_command_exists(): void
    {
        $registered = array_keys($this->app->make(Kernel::class)->all());

        foreach ($this->scheduledCommands() as $command) {
            $this->assertContains(
                $command,
                $registered,
                sprintf('The schedule runs %s, which this application does not define.', $command),
            );
        }
    }

    #[Test]
    public function no_scheduled_sweep_can_pile_up_on_itself(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (! is_string($event->command) || self::artisanCommandIn($event->command) === 'inspire') {
                continue;
            }

            // These sweeps take row locks. A run that starts while the previous
            // one is still working doubles the contention to redo work already
            // in progress, and on a bad day never catches up.
            $this->assertTrue(
                $event->withoutOverlapping,
                sprintf('%s may overlap with itself.', (string) $event->command),
            );

            // Every application server carries the same cron.
            $this->assertTrue(
                $event->onOneServer,
                sprintf('%s would run once per application server.', (string) $event->command),
            );
        }
    }
}
