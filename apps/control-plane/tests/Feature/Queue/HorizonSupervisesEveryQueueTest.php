<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Laravel\Horizon\ProvisioningPlan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every queue this platform pushes to must have something that drains it.
 *
 * The repository required laravel/horizon, the deployment installed a
 * `lynomia-horizon` systemd unit, and there was no config/horizon.php at all —
 * so Horizon would have run on its packaged defaults, which watch `default`.
 * The platform's work is on `provisioning` and `payments`. Both would have
 * filled up silently: machines never built, payments never settled, and no
 * error anywhere, because nothing failed. Nothing ran.
 *
 * The queue names are read from the source rather than listed here, so a new
 * queue introduced without a supervisor fails this test rather than production.
 */
final class HorizonSupervisesEveryQueueTest extends TestCase
{
    private const string SRC = __DIR__.'/../../../src';

    /**
     * Queue names the application actually pushes to.
     *
     * @return list<string>
     */
    private function queuesInUse(): array
    {
        // Everything that does not name a queue lands on this one.
        $queues = ['default'];

        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC));

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // `const QUEUE = 'provisioning';` and `public string $queue = 'payments';`
            if (preg_match_all('/(?:const\s+(?:string\s+)?QUEUE|\$queue)\s*=\s*[\'"]([a-z0-9_-]+)[\'"]/i', $source, $matches) > 0) {
                foreach ($matches[1] as $queue) {
                    $queues[] = $queue;
                }
            }
        }

        return array_values(array_unique($queues));
    }

    /**
     * @return array<string, list<string>>
     */
    private function supervisedQueues(): array
    {
        /** @var array<string, array<string, mixed>> $supervisors */
        $supervisors = config('horizon.defaults', []);

        $covered = [];

        foreach ($supervisors as $name => $supervisor) {
            /** @var list<string> $queues */
            $queues = (array) ($supervisor['queue'] ?? []);
            $covered[$name] = $queues;
        }

        return $covered;
    }

    #[Test]
    public function every_queue_the_platform_uses_has_a_supervisor(): void
    {
        $supervised = array_merge(...array_values($this->supervisedQueues()));

        foreach ($this->queuesInUse() as $queue) {
            $this->assertContains(
                $queue,
                $supervised,
                sprintf(
                    'Nothing drains the "%s" queue. Horizon supervises: %s.',
                    $queue,
                    implode(', ', $supervised),
                ),
            );
        }
    }

    #[Test]
    public function the_provisioning_supervisor_retries_nothing_a_class_leaves_unsaid(): void
    {
        /*
         * The provisioning engine owns retrying. A queue-level retry
         * re-executes a job whose provider call may already have built a
         * machine, and the worker cannot know whether it did — which is how a
         * customer gets two servers and one invoice.
         *
         * This pins the supervisor's ceiling, and that is all it pins. A class
         * that declares its own `tries` beats it: the payload's `maxTries` is
         * read before the worker's option (`Worker.php:679` and `:707`), and a
         * queued listener's `$tries` is carried into that payload by
         * `Events\Dispatcher::propagateListenerOptions()`. Two classes on this
         * queue do exactly that — `InstallWordPressOnceTheAccountExists` runs
         * up to 5 times and `EnforceServiceStateForSubscription` up to 3. Both
         * converge on retry (a unique idempotency key and in-lock guards), so
         * they are not a double build; but "provisioning is never retried by
         * the queue", which this test used to be called, was false.
         */
        $this->assertSame(1, (int) config('horizon.defaults.supervisor-provisioning.tries'));
    }

    #[Test]
    public function every_process_pool_horizon_starts_has_a_wait_threshold(): void
    {
        /*
         * Horizon looks thresholds up by the key of each process pool it runs,
         * `connection:queue`, where `queue` is one name when the supervisor
         * balances (a pool per queue) and the whole comma-joined list when it
         * does not (`Supervisor::createProcessPools()`). The keys are derived
         * here from Horizon's own resolved options rather than assumed to be
         * one per queue, so a supervisor that lists two queues without
         * balancing demands the key Horizon will actually read. A pool with no
         * key falls back to Horizon's 60 seconds silently.
         */
        $waits = (array) config('horizon.waits');

        foreach (ProvisioningPlan::get('probe')->toSupervisorOptions() as $environment => $supervisors) {
            foreach ($supervisors as $options) {
                $pools = $options->balancing() ? explode(',', $options->queue) : [$options->queue];

                foreach ($pools as $queue) {
                    $key = $options->connection.':'.$queue;

                    $this->assertArrayHasKey($key, $waits, "Horizon ({$environment}) runs a pool keyed {$key} and config/horizon.php sets no wait threshold for it.");
                }
            }
        }
    }

    #[Test]
    public function no_supervisor_kills_a_worker_faster_than_a_provider_call_can_finish(): void
    {
        /** @var array<string, int> $perKind */
        $perKind = (array) config('provisioning.timeout_seconds', []);

        $this->assertNotSame([], $perKind, 'The engine declares no timeouts; this test is checking nothing.');

        // The longest, not the default: a dedicated provision is allowed an
        // hour and a half, and the supervisor has to outlive the longest job it
        // may be running, not the average one. The first version of this test
        // read a key that does not exist, fell back to 600, and passed against
        // a supervisor timeout that would have killed a dedicated build.
        $this->assertGreaterThan(
            max(array_map(static fn (mixed $s): int => (int) $s, array_values($perKind))),
            (int) config('horizon.defaults.supervisor-provisioning.timeout'),
            'A worker killed mid-provision leaves a machine on a hypervisor that nothing in the database records.',
        );
    }

    #[Test]
    public function horizon_is_namespaced_per_environment(): void
    {
        // Two deployments pointed at one Redis must not read each other's
        // queues; a staging worker consuming a production build is the failure.
        $this->assertStringContainsString((string) config('app.env'), (string) config('horizon.prefix'));
    }
}
