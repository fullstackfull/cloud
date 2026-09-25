<?php

declare(strict_types=1);

namespace App\Queue;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Refuses to start a queue worker whose timeout outlives its connection's
 * retry clock.
 *
 * CI checks the configuration file. This checks the deployment: the clocks are
 * `env()`-overridable, and a server with `REDIS_PROVISIONING_RETRY_AFTER=300`
 * in its environment reinstates F-08 — a provisioning job run twice at once —
 * while every test stays green, because CI sets none of those variables. So
 * the check runs where the values are finally resolved, at the moment a worker
 * starts, and fails that start loudly instead of letting the worker run.
 *
 * Two shapes of invocation:
 *
 *  - `horizon` starts every supervisor its configuration describes, so it is
 *    refused if **any** supervisor in **any** environment is unsafe
 *    ({@see QueueRetryClocks::violations()}).
 *  - `horizon:supervisor`, `horizon:work`, `queue:work` and `queue:listen` name
 *    a connection and a `--timeout` of their own, so the invocation itself is
 *    measured. That is the path a hand-started worker takes — `scripts/serve.sh`
 *    ships one — and it is also the path Horizon's own spawned processes take,
 *    which are built from the same supervisor options and are accepted
 *    whenever the configuration is.
 *
 * The invocation is read by binding a copy of the input to the command's own
 * definition, so defaults (`--timeout=60`, the default connection) are the
 * command's and not restated here. An input that does not bind is left alone:
 * the command itself will refuse it a moment later with Symfony's own message.
 *
 * Not testable by running a real command under `APP_ENV=testing`: Laravel's
 * console kernel only reroutes Symfony's command events when
 * `! runningUnitTests()`, so `CommandStarting` never fires in the test
 * environment. The tests call {@see handle()} directly.
 */
final readonly class RefuseAWorkerThatWouldRunAJobTwice
{
    /** Commands that start a worker with a connection and a timeout of their own. */
    private const array WORKERS = ['horizon:supervisor', 'horizon:work', 'queue:work', 'queue:listen'];

    public function __construct(
        private Kernel $artisan,
    ) {}

    public function handle(CommandStarting $event): void
    {
        $clocks = QueueRetryClocks::fromConfig();

        if ($event->command === 'horizon') {
            $this->refuseIfAny($clocks->violations());

            return;
        }

        if (! in_array($event->command, self::WORKERS, true)) {
            return;
        }

        $input = $this->bound($event->command, $event->input);

        if ($input === null) {
            return;
        }

        $connection = $input->getArgument('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : (string) config('queue.default');

        $this->refuseIfAny($clocks->violationsForWorker(
            $connection,
            (int) $input->getOption('timeout'),
            sprintf('This `%s` invocation', $event->command),
        ));
    }

    private function bound(string $name, ?InputInterface $input): ?InputInterface
    {
        $command = $this->artisan->all()[$name] ?? null;

        if ($command === null || $input === null) {
            return null;
        }

        try {
            $command->mergeApplicationDefinition();

            $copy = clone $input;
            $copy->bind($command->getDefinition());

            return $copy;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function refuseIfAny(array $violations): void
    {
        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "Refusing to start a queue worker that could run one job twice at the same time (F-08):\n - "
            .implode("\n - ", $violations)
            ."\nEvery worker's timeout must be shorter than the retry_after of the connection it pops from.",
        );
    }
}
