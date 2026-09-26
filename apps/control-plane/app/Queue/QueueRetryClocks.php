<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Whether a queue connection can hand a job to a second worker while the first
 * is still running it.
 *
 * ---------------------------------------------------------------------------
 * The failure this exists to prevent (F-08)
 * ---------------------------------------------------------------------------
 *
 * A Redis worker that pops a message does not delete it; it moves it to a
 * `:reserved` set stamped `now + retry_after`. If the job is still running
 * when that stamp passes, the next worker to look migrates it back onto the
 * queue and runs it again — while the first worker is still inside it. The
 * only thing that makes that impossible is the worker's own timeout: a
 * `pcntl_alarm` that kills the child before the reservation expires.
 *
 * So the invariant is one inequality per worker: **its timeout must be shorter
 * than the `retry_after` of the connection it pops from.** This repository
 * shipped `retry_after = 90` on the one Redis connection every supervisor used,
 * against supervisor timeouts of 120, 1,800 and 5,700 seconds. Every listener
 * on `payments` carries `tries = 5`, so settlement, refund, dunning and renewal
 * could each run concurrently with themselves: one dispatch was measured
 * producing five executions, ninety seconds apart, each starting while the
 * previous one was still alive.
 *
 * The fix is three connections on the same Redis keys, each with a clock
 * longer than the longest supervisor that pops from it — see
 * `config/queue.php`. This class is what keeps that true.
 *
 * ---------------------------------------------------------------------------
 * What it reads, and what it cannot
 * ---------------------------------------------------------------------------
 *
 * Supervisors are read the way Horizon's `ProvisioningPlan` builds them:
 * `defaults` merged into **every** environment block with
 * `array_replace_recursive`. Every environment is checked, not only the one
 * this process runs as, so a bad staging block fails production's boot check
 * and CI alike, and which environment `horizon --environment=…` names cannot
 * matter. Every supervisor watching a queue is measured, not the first one
 * found — taking the first made the answer depend on declaration order.
 *
 * A timeout of `0` is read as the **worst** case, not the smallest:
 * `pcntl_alarm(max(0, 0))` cancels the alarm, so the job has no upper bound at
 * all and every reservation will eventually expire under it.
 *
 * What a single class declares for itself — a job's or queued listener's own
 * `$timeout`, which beats the worker's `--timeout` at `Worker.php:353`
 * (`return $job && ! is_null($job->timeout()) ? $job->timeout() : $options->timeout;`)
 * — is not visible from configuration. It is read from the payload the
 * framework actually builds, by the test suite
 * (`tests/Support/Queue/ThePayloadTheWorkerWillRead.php`), because only a test
 * can enumerate the application's queued classes and dispatch each one.
 *
 * The clocks themselves are `env()`-overridable
 * (`REDIS_QUEUE_RETRY_AFTER`, `REDIS_PROVISIONING_RETRY_AFTER`,
 * `REDIS_INFRASTRUCTURE_RETRY_AFTER`, `HORIZON_PROVISIONING_TIMEOUT`), which is
 * why this is also enforced at the moment a worker starts rather than only in
 * CI: a deployment that sets one of them wrongly is refused where it runs.
 * See {@see RefuseAWorkerThatWouldRunAJobTwice}.
 */
final readonly class QueueRetryClocks
{
    /** Drivers that reserve a message for `retry_after` seconds. */
    private const array RESERVING_DRIVERS = ['redis', 'database', 'beanstalkd'];

    /**
     * @param  array<string, mixed>  $connections  `config('queue.connections')`
     * @param  array<string, mixed>  $defaults  `config('horizon.defaults')`
     * @param  array<string, mixed>  $environments  `config('horizon.environments')`
     */
    public function __construct(
        private array $connections,
        private array $defaults,
        private array $environments,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (array) config('queue.connections', []),
            (array) config('horizon.defaults', []),
            (array) config('horizon.environments', []),
        );
    }

    /**
     * Every supervisor Horizon can start, in every environment.
     *
     * The option defaults are Horizon's own (`SupervisorOptions`): a timeout
     * of 60, `tries` of 0 and a backoff of 0 when a block leaves them out.
     *
     * @return list<array{environment: string, name: string, connection: string, queues: list<string>, timeout: int, tries: int, backoff: string}>
     */
    public function supervisors(): array
    {
        $plans = $this->environments === [] ? ['*' => []] : $this->environments;

        $supervisors = [];

        foreach ($plans as $environment => $plan) {
            /** @var array<string, array<string, mixed>> $merged */
            $merged = array_replace_recursive($this->defaults, (array) $plan);

            foreach ($merged as $name => $options) {
                $supervisors[] = [
                    'environment' => (string) $environment,
                    'name' => (string) $name,
                    'connection' => (string) ($options['connection'] ?? ''),
                    'queues' => self::names($options['queue'] ?? []),
                    'timeout' => (int) ($options['timeout'] ?? 60),
                    'tries' => (int) ($options['tries'] ?? 0),
                    'backoff' => implode(',', (array) ($options['backoff'] ?? 0)),
                ];
            }
        }

        return $supervisors;
    }

    /**
     * The supervisors, in any environment, that pop from a queue.
     *
     * @return list<array{environment: string, name: string, connection: string, queues: list<string>, timeout: int, tries: int, backoff: string}>
     */
    public function supervisorsOf(string $queue): array
    {
        return array_values(array_filter(
            $this->supervisors(),
            static fn (array $supervisor): bool => in_array($queue, $supervisor['queues'], true),
        ));
    }

    /**
     * How long a connection lets a popped message stay reserved, or null when
     * the connection does not exist or does not reserve at all.
     */
    public function retryAfter(string $connection): ?int
    {
        $config = $this->connections[$connection] ?? null;

        if (! is_array($config) || ! in_array($config['driver'] ?? null, self::RESERVING_DRIVERS, true)) {
            return null;
        }

        // The connectors' own fallback when the key is absent.
        return (int) ($config['retry_after'] ?? 60);
    }

    /**
     * Every way the configured supervisors can run one job twice at once.
     *
     * @return list<string> empty when there is none
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->supervisors() as $supervisor) {
            array_push($violations, ...$this->violationsForWorker(
                $supervisor['connection'],
                $supervisor['timeout'],
                sprintf('Horizon supervisor "%s" (%s environment)', $supervisor['name'], $supervisor['environment']),
            ));
        }

        return $violations;
    }

    /**
     * Whether one worker — a supervisor, or a `queue:work` somebody typed —
     * could outlive the reservation of the message it is running.
     *
     * @return list<string> empty when it cannot
     */
    public function violationsForWorker(string $connection, int $timeout, string $who): array
    {
        $config = $this->connections[$connection] ?? null;

        if (! is_array($config)) {
            return [sprintf('%s pops from connection "%s", which config/queue.php does not define.', $who, $connection)];
        }

        $retryAfter = $this->retryAfter($connection);

        if ($retryAfter === null) {
            // sync, sqs and the rest do not hand a running job to anyone else
            // on a clock this file controls.
            return [];
        }

        if ($timeout <= 0) {
            return [sprintf(
                '%s has a timeout of %d, which installs no alarm at all, so a job on connection "%s" can outlive its %d-second reservation and be run again while it is still running.',
                $who,
                $timeout,
                $connection,
                $retryAfter,
            )];
        }

        if ($timeout >= $retryAfter) {
            return [sprintf(
                '%s may run a job for %d seconds, but connection "%s" re-reserves it after %d: a second worker can start the same job while the first is still inside it.',
                $who,
                $timeout,
                $connection,
                $retryAfter,
            )];
        }

        return [];
    }

    /**
     * Queue names as Horizon reads them: a list, or one comma-separated string.
     *
     * Trimmed, erring toward the alarm. `'default, payments'` is a spelling
     * people write; untrimmed, its second name matches no queue, the
     * supervisor drops out of every measurement against `payments`, and a
     * rule reading "the longest supervisor on this queue" sees none — under
     * which a clock that is too short looks safe.
     *
     * @return list<string>
     */
    private static function names(mixed $queue): array
    {
        $names = [];

        foreach ((array) $queue as $entry) {
            foreach (explode(',', (string) $entry) as $name) {
                $name = trim($name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
