<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * What a test needs in order to be about a queue rather than about a function
 * call.
 *
 * Everything here exists to remove one comfortable assumption at a time:
 *
 *  - The rows are **committed**. RefreshDatabase's transaction is invisible to
 *    another process, so a worker started against it would find no job at all.
 *    Fixtures go in through {@see committed()} and come out again in the
 *    teardown.
 *  - The queue is **Redis**, on database 15, emptied before each test. Jobs
 *    pin their own queue names — that is the behaviour under test — so
 *    isolation is by database index and not by inventing a queue name.
 *  - The worker is **`php artisan queue:work` in its own process**. It shares
 *    no memory, no container and no transaction with the test; the only things
 *    it is handed are the queue to read and the database to write.
 *  - The hypervisor is **shared**, through a state file the fake provider
 *    reads and writes when it is given one. Without it the worker's fake has
 *    never heard of the customer's machine, and nothing that operates on an
 *    existing machine — a reinstall, a suspension, a resize — could be proved
 *    across processes at all.
 */
abstract class WorkerHarness extends TestCase
{
    /** A second connection to the same database, outside the test transaction. */
    protected const string CONNECTION = 'queue_test';

    /** Reserved for these suites, and emptied before every test in them. */
    protected const int REDIS_DATABASE = 15;

    /**
     * Rows written for real, newest last, deleted in reverse in the teardown.
     *
     * @var list<Model>
     */
    private array $committed = [];

    /** Where the fake hypervisor keeps its fleet for this test. */
    private string $fleetPath = '';

    /**
     * The fleet file, for a subclass that starts a process of its own.
     *
     * A command run without it gets a hypervisor that has never heard of the
     * machines this test built, and every assertion after that would be about
     * an empty fake.
     */
    protected function fleetPath(): string
    {
        return $this->fleetPath;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisIsReachable()) {
            /*
             * Skipped on a developer's machine, failed in CI.
             *
             * A skip is the right answer for somebody who has not started
             * Redis; it is the wrong answer for the build, because these are
             * the only tests in the repository that prove the queue works at
             * all — and a suite that quietly skips them reports green for a
             * platform whose provisioning does not run.
             */
            if (self::runningInCi()) {
                $this->fail('CI must run the queue proofs, and Redis is not reachable.');
            }

            $this->markTestSkipped('This test needs a real Redis; there is none on this machine.');
        }

        config()->set('database.connections.'.self::CONNECTION, config('database.connections.pgsql'));

        config()->set('database.redis.default.database', self::REDIS_DATABASE);

        /*
         * The Redis manager reads its configuration once, when the container
         * builds it — which the test case has already done by the time setUp
         * runs. Changing the config afterwards changes nothing until the
         * manager is rebuilt, and the first version of this harness spent its
         * messages on database 0 while the worker read database 15 and
         * correctly reported an empty queue.
         */
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        Redis::clearResolvedInstances();
        Queue::clearResolvedInstances();

        Redis::connection()->flushdb();

        // The application under test pushes to Redis rather than running jobs
        // inline, which is the whole point.
        config()->set('queue.default', 'redis');

        $this->fleetPath = sys_get_temp_dir().'/lynomia-fake-fleet-'.getmypid().'-'.uniqid().'.state';
        config()->set('compute.fake.state_path', $this->fleetPath);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->committed) as $model) {
            try {
                $model->newQueryWithoutScopes()->whereKey($model->getKey())->forceDelete();
            } catch (\Throwable) {
                // A row a cascade already took with its parent. The teardown's
                // job is to leave the database clean, not to be right about
                // the order it managed it in.
            }
        }

        $this->committed = [];

        if ($this->fleetPath !== '' && is_file($this->fleetPath)) {
            @unlink($this->fleetPath);
        }

        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
            // The queue is a scratch key; a Redis that has gone away between
            // the test and its teardown is not a failure of the test.
        }

        parent::tearDown();
    }

    /**
     * Writes a row for real, so another process can see it.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    protected function committed(Model $model): Model
    {
        $model->setConnection(self::CONNECTION)->save();

        $this->committed[] = $model;

        return $model;
    }

    /**
     * Builds fixtures on the committed connection, records everything they
     * create, and puts the default connection back.
     *
     * Factories reach for their parents — a subscription makes a plan, a plan
     * makes a product — and every one of those rows has to be visible to
     * another process too. Writing them one at a time through
     * {@see committed()} misses the ones a factory made on the caller's
     * behalf, and a foreign key pointing into this test's open transaction is
     * a row the worker cannot see.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $build
     * @return TReturn
     */
    protected function outsideTheTransaction(callable $build): mixed
    {
        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection(self::CONNECTION);

        Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            foreach ($payload as $model) {
                if ($model instanceof Model) {
                    $this->committed[] = $model;
                }
            }
        });

        try {
            return $build();
        } finally {
            Event::forget('eloquent.created: *');

            DB::setDefaultConnection($previous);
        }
    }

    /**
     * How many messages are waiting on a queue.
     *
     * Read through the same connection the application pushes with, so the
     * prefix is applied once and by the same code.
     */
    protected function queued(string $queue): int
    {
        return (int) Redis::connection()->llen('queues:'.$queue);
    }

    /**
     * Whether this is a build rather than somebody's laptop.
     *
     * `CI` is set by GitHub Actions and by every other runner worth naming.
     */
    protected static function runningInCi(): bool
    {
        $flag = getenv('CI');

        return is_string($flag) && $flag !== '' && $flag !== 'false' && $flag !== '0';
    }

    protected function redisIsReachable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Runs a real worker until the queue is empty, and returns its output.
     */
    protected function runWorker(string $queue, int $timeoutSeconds = 120): Process
    {
        $worker = $this->buildWorker($queue, $timeoutSeconds);

        $worker->run();

        return $worker;
    }

    /**
     * Runs a worker and fails the test with its output if it did not exit
     * cleanly.
     */
    protected function work(string $queue, int $timeoutSeconds = 120): Process
    {
        $worker = $this->runWorker($queue, $timeoutSeconds);

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());

        return $worker;
    }

    /**
     * Starts a worker without waiting for it, so the test can kill it.
     */
    protected function startWorker(string $queue, int $timeoutSeconds = 120): Process
    {
        $worker = $this->buildWorker($queue, $timeoutSeconds);

        $worker->start();

        return $worker;
    }

    protected function buildWorker(string $queue, int $timeoutSeconds = 120): Process
    {
        return new Process(
            [
                PHP_BINARY,
                'artisan',
                'queue:work',
                'redis',
                '--queue='.$queue,
                '--stop-when-empty',
                '--tries=1',
                '--no-interaction',
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'QUEUE_CONNECTION' => 'redis',
                // The same Redis database, so the worker reads the queue this
                // test wrote and not the one a developer is using.
                'REDIS_DB' => (string) self::REDIS_DATABASE,
                // The committed database, not the test transaction.
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
                /*
                 * A mailer that accepts everything. The worker reads the
                 * environment file rather than phpunit.xml, which points it at
                 * an SMTP server no test machine is running — and a chain test
                 * that ended in a connection refused would be measuring the
                 * absence of a mail server rather than the queue.
                 */
                'MAIL_MAILER' => 'array',
                // The same fleet, so the worker's hypervisor has heard of the
                // machines this test created.
                'COMPUTE_FAKE_STATE_PATH' => $this->fleetPath,
            ],
            null,
            $timeoutSeconds,
        );
    }

    /**
     * A committed customer, cluster, node, pool, service and queued job: what a
     * paid order leaves behind for a worker to act on.
     */
    protected function committedOrder(?string $hostname = null): ProvisioningJob
    {
        $customer = $this->committed(Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW']));

        $cluster = $this->committed(ComputeCluster::factory()->make(['status' => 'active']));

        $node = $this->committed(
            ComputeNode::factory()->withCapacity(64, 262_144, 4_000)->make(['cluster_id' => $cluster->id]),
        );

        // Without storage of the requested class the scheduler correctly
        // refuses to place anything, and the worker's first run reported
        // exactly that — which is how this fixture came to be complete.
        $this->committed(ComputeStorage::factory()->available(4_000)->make([
            'cluster_id' => $cluster->id,
            'node_id' => $node->id,
        ]));

        $pool = $this->committed(IpPool::factory()->make(['is_active' => true, 'ip_version' => 4]));

        // A pool with no addresses in it allocates nothing, and the worker said
        // so on its second run. The subnet is seeded through the platform's own
        // action so the address rows are exactly what allocation expects.
        // A customer-facing network with a bridge: the handler refuses to
        // attach a machine to a segment it cannot name, which is what its third
        // run reported.
        $network = $this->committed(Network::factory()->make(['bridge' => 'vmbr1', 'vlan_id' => 1234]));

        $subnet = $this->committed(
            Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
                ->make(['ip_pool_id' => $pool->id, 'network_id' => $network->id]),
        );

        DB::setDefaultConnection(self::CONNECTION);

        try {
            app(SeedSubnetAddresses::class)->execute($subnet);
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        $service = $this->committed(Service::factory()->make([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]));

        $job = ProvisioningJob::factory()->make([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::Queued,
            'provider' => $cluster->driver->value,
            'idempotency_key' => 'worker-test:'.Str::ulid(),
            'payload' => [
                'cluster_id' => $cluster->id,
                'ip_pool_id' => $pool->id,
                'vcpu' => 2,
                'memory_mib' => 2048,
                'disk_gib' => 20,
                'hostname' => $hostname ?? 'worker-test-'.Str::lower(Str::random(6)),
            ],
        ]);

        return $this->committed($job);
    }

    /**
     * Polls until the condition holds, or fails the test.
     *
     * A fixed sleep would be either flaky or slow; a job is claimed in
     * milliseconds on a warm machine and in a second or two on a cold one.
     */
    protected function waitUntil(callable $condition, float $timeoutSeconds = 30.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition() === true) {
                return;
            }

            usleep(50_000);
        }

        $this->fail('The worker never reached the state this test needed.');
    }
}
