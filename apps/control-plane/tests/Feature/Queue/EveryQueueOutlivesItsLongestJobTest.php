<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Queue\QueueRetryClocks;
use App\Queue\RefuseAWorkerThatWouldRunAJobTwice;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\WorkerCommandString;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * No worker may run a job for longer than its connection keeps the job
 * reserved (F-08).
 *
 * A Redis worker that is still inside a job when the job's reservation
 * expires does not stop; the next worker to look migrates the message back
 * onto the queue and starts it again. The repository shipped `retry_after =
 * 90` on the one connection every supervisor popped from, under supervisor
 * timeouts of 120, 1,800 and 5,700 seconds, so every job that took more than
 * ninety seconds ran again beside itself — and the payments listeners carry
 * five tries, so one settlement could be in flight five times.
 *
 * The configured half is guarded here, against every supervisor in every
 * environment and against what a worker invocation says for itself; what one
 * class can declare on its own is guarded by
 * {@see EveryRetriedPaymentsListenerWaitsBetweenAttemptsTest}.
 */
final class EveryQueueOutlivesItsLongestJobTest extends TestCase
{
    #[Test]
    public function every_supervisor_is_killed_before_its_connection_hands_the_job_to_another(): void
    {
        // Read straight from the two files, so this fails for the reason it
        // names even if the class below is wrong.
        foreach ((array) config('horizon.defaults') as $name => $supervisor) {
            $connection = (string) ($supervisor['connection'] ?? '');
            $retryAfter = config("queue.connections.{$connection}.retry_after");

            $this->assertIsInt($retryAfter, "{$name} pops from {$connection}, which has no retry clock.");
            $this->assertLessThan(
                $retryAfter,
                (int) $supervisor['timeout'],
                "{$name} may run a job for {$supervisor['timeout']}s; {$connection} hands it to a second worker after {$retryAfter}s.",
            );
        }
    }

    #[Test]
    public function the_shipped_configuration_has_no_way_to_run_a_job_twice(): void
    {
        $this->assertSame([], QueueRetryClocks::fromConfig()->violations());
    }

    #[Test]
    public function the_shipped_clocks_are_the_ones_intended(): void
    {
        $clocks = QueueRetryClocks::fromConfig();

        $this->assertSame(
            ['redis' => 180, 'redis-provisioning' => 5760, 'redis-infrastructure' => 1860],
            [
                'redis' => $clocks->retryAfter('redis'),
                'redis-provisioning' => $clocks->retryAfter('redis-provisioning'),
                'redis-infrastructure' => $clocks->retryAfter('redis-infrastructure'),
            ],
        );

        // The three connections are one Redis and one keyspace: a job pushed on
        // `redis` onto `provisioning` is popped by a worker on
        // `redis-provisioning`. Only the clock differs.
        foreach (['redis-provisioning', 'redis-infrastructure'] as $connection) {
            $this->assertSame(
                array_diff_key((array) config('queue.connections.redis'), ['retry_after' => true]),
                array_diff_key((array) config("queue.connections.{$connection}"), ['retry_after' => true]),
                $connection,
            );
        }

        $supervised = [];

        foreach ($clocks->supervisors() as $supervisor) {
            $supervised[$supervisor['name']] = [$supervisor['connection'], $supervisor['timeout']];
        }

        $this->assertSame([
            'supervisor-provisioning' => ['redis-provisioning', 5700],
            'supervisor-payments' => ['redis', 120],
            'supervisor-infrastructure' => ['redis-infrastructure', 1800],
            'supervisor-notifications' => ['redis', 90],
            'supervisor-default' => ['redis', 120],
        ], $supervised);
    }

    #[Test]
    public function a_clock_shorter_than_its_supervisor_is_refused(): void
    {
        $violations = $this->clocks(['redis' => 90])->violations();

        $this->assertNotSame([], $violations);
        $this->assertStringContainsString('supervisor-payments', implode("\n", $violations));
        $this->assertStringContainsString('re-reserves it after 90', implode("\n", $violations));
    }

    #[Test]
    public function a_clock_equal_to_its_supervisor_is_refused(): void
    {
        $this->assertNotSame([], $this->clocks(['redis-provisioning' => 5700])->violations());
    }

    #[Test]
    public function a_timeout_of_zero_is_the_worst_case_not_the_smallest(): void
    {
        $clocks = $this->clocks([], ['supervisor-payments' => ['timeout' => 0]]);

        $this->assertStringContainsString('installs no alarm', implode("\n", $clocks->violations()));
    }

    #[Test]
    public function every_supervisor_on_a_queue_is_measured_not_the_first(): void
    {
        $clocks = $this->clocks([], [
            'supervisor-payments-overflow' => [
                'connection' => 'redis',
                'queue' => ['default', 'payments'],
                'timeout' => 600,
                'tries' => 5,
            ],
        ]);

        $this->assertCount(2, array_filter(
            $clocks->supervisorsOf('payments'),
            static fn (array $supervisor): bool => $supervisor['environment'] === 'production',
        ));
        $this->assertStringContainsString('supervisor-payments-overflow', implode("\n", $clocks->violations()));
    }

    #[Test]
    public function a_comma_separated_queue_string_is_read_name_by_name(): void
    {
        $clocks = $this->clocks([], ['supervisor-payments' => ['queue' => 'default, payments']]);

        $this->assertSame(['default', 'payments'], $clocks->supervisorsOf('payments')[0]['queues']);
    }

    #[Test]
    public function an_environment_block_is_measured_as_horizon_merges_it(): void
    {
        $clocks = new QueueRetryClocks(
            (array) config('queue.connections'),
            (array) config('horizon.defaults'),
            [...(array) config('horizon.environments'), 'staging' => ['supervisor-provisioning' => ['timeout' => 6000]]],
        );

        $this->assertStringContainsString('(staging environment)', implode("\n", $clocks->violations()));
    }

    #[Test]
    public function a_worker_invocation_is_refused_when_its_timeout_outlives_its_connection(): void
    {
        $this->assertRefused('queue:work redis --queue=provisioning --timeout=5700');
        // The command's own default connection and timeout, not restated ones.
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 60);
        $this->assertRefused('queue:work --queue=payments');
    }

    #[Test]
    public function a_worker_invocation_within_its_clock_is_accepted(): void
    {
        $this->assertAccepted('queue:work redis-provisioning --queue=provisioning --timeout=5700');
        $this->assertAccepted('queue:work redis --queue=payments,notifications,default --timeout=120');
        config()->set('queue.default', 'redis');
        $this->assertAccepted('queue:work --queue=payments');
        $this->assertAccepted('queue:listen redis --timeout=60');
        // Not a worker at all.
        config()->set('queue.connections.redis.retry_after', 1);
        $this->assertAccepted('migrate --force');
    }

    #[Test]
    public function horizon_itself_is_refused_when_any_supervisor_is(): void
    {
        $this->assertAccepted('horizon');

        config()->set('queue.connections.redis-infrastructure.retry_after', 900);

        $this->assertRefused('horizon');
    }

    #[Test]
    public function every_process_horizon_spawns_from_the_shipped_configuration_is_accepted(): void
    {
        $spawned = 0;

        foreach (ProvisioningPlan::get('probe')->toSupervisorOptions() as $environment => $supervisors) {
            foreach ($supervisors as $options) {
                foreach ([SupervisorCommandString::fromOptions($options), WorkerCommandString::fromOptions($options)] as $line) {
                    // `exec /usr/bin/php artisan horizon:work redis …` → `horizon:work redis …`
                    $this->assertMatchesRegularExpression('/^exec .+? artisan (horizon:\S+ .*)$/', $line);
                    preg_match('/^exec .+? artisan (horizon:\S+ .*)$/', $line, $matches);

                    $this->assertAccepted($matches[1]);
                    $spawned++;
                }
            }
        }

        // Three environments × five supervisors × supervisor and worker.
        $this->assertSame(30, $spawned);
    }

    #[Test]
    public function a_horizon_worker_spawned_from_a_bad_configuration_is_refused(): void
    {
        config()->set('queue.connections.redis-provisioning.retry_after', 180);

        $options = ProvisioningPlan::get('probe')->toSupervisorOptions()['production']['supervisor-provisioning'];

        preg_match('/^exec .+? artisan (horizon:\S+ .*)$/', WorkerCommandString::fromOptions($options), $matches);

        $this->assertRefused($matches[1]);
    }

    /**
     * @param  array<string, int>  $retryAfter  connection => retry_after
     * @param  array<string, array<string, mixed>>  $supervisors  supervisor => options merged over the shipped ones
     */
    private function clocks(array $retryAfter = [], array $supervisors = []): QueueRetryClocks
    {
        $connections = (array) config('queue.connections');

        foreach ($retryAfter as $connection => $seconds) {
            $connections[$connection]['retry_after'] = $seconds;
        }

        return new QueueRetryClocks(
            $connections,
            array_replace_recursive((array) config('horizon.defaults'), $supervisors),
            (array) config('horizon.environments'),
        );
    }

    private function start(string $commandLine): void
    {
        $input = new StringInput($commandLine);
        $name = (string) $input->getFirstArgument();

        (new RefuseAWorkerThatWouldRunAJobTwice($this->app->make(Kernel::class)))
            ->handle(new CommandStarting($name, $input, new NullOutput));
    }

    private function assertRefused(string $commandLine): void
    {
        try {
            $this->start($commandLine);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could run one job twice', $e->getMessage());

            return;
        }

        $this->fail("`{$commandLine}` was allowed to start.");
    }

    private function assertAccepted(string $commandLine): void
    {
        $this->start($commandLine);

        $this->addToAssertionCount(1);
    }
}
