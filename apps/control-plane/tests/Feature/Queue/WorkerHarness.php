<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
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
use Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore;
use RuntimeException;
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
     * Which Redis database this run owns.
     *
     * The index used to be the constant above and nothing else, which is
     * correct for one checkout and wrong for several. `setUp()` empties the
     * whole database — `flushdb`, not a prefixed delete — so two checkouts
     * running these suites at once delete each other's queued messages
     * mid-test, and a worker subprocess in one can pop a message belonging to
     * the other and fail looking up a row in a database it cannot see. The
     * failures are non-deterministic, land in whichever suite happened to be
     * unlucky, and name modules the change under test never touched: measured
     * here as fifteen Simulation failures that became none the moment the two
     * runs stopped sharing an index.
     *
     * So the index is a default rather than a fact. `REDIS_DB` is already the
     * variable `config/database.php` reads and already the one this harness
     * hands its worker subprocesses, so a checkout that wants its own
     * keyspace sets that and everything downstream follows.
     */
    protected static function redisDatabase(): int
    {
        $configured = env('REDIS_DB');

        return is_numeric($configured) ? (int) $configured : self::REDIS_DATABASE;
    }

    /** Where the fake hypervisor keeps its fleet for this test. */
    private string $fleetPath = '';

    /**
     * Every controlled simulator that has to be the same simulator in two
     * processes, as `config key => environment variable`.
     *
     * Compute and the registrar were here first, one field each, because they
     * were the only two families that could remember anything across a process
     * boundary at all. The other five could not, which is why every proof in
     * this directory used to be about compute or a domain name. One list
     * rather than seven fields: a family added to
     * {@see ControlledSimulationStore}
     * and not added here is a family whose worker starts with an empty
     * provider, and the test that noticed would fail somewhere far away from
     * the reason.
     *
     * @var array<string, string>
     */
    private const array SIMULATION_STATE = [
        'compute.fake.state_path' => 'COMPUTE_FAKE_STATE_PATH',
        'dedicated.fake.state_path' => 'DEDICATED_FAKE_STATE_PATH',
        'hosting.fake.state_path' => 'HOSTING_FAKE_STATE_PATH',
        'backups.fake.state_path' => 'BACKUPS_FAKE_STATE_PATH',
        'dns.fake.state_path' => 'DNS_FAKE_STATE_PATH',
        'dns.fake_reverse.state_path' => 'DNS_FAKE_REVERSE_STATE_PATH',
        'domains.fake.state_path' => 'DOMAINS_FAKE_STATE_PATH',
    ];

    /**
     * The file each of those families is using for this test.
     *
     * @var array<string, string> config key => path
     */
    private array $simulationPaths = [];

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

        config()->set('database.redis.default.database', static::redisDatabase());

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

        /*
         * One directory per test, so that two tests running one after another
         * cannot see each other's providers and a run that died without its
         * teardown cannot seed the next one.
         */
        $root = sys_get_temp_dir().'/lynomia-simulation-'.getmypid().'-'.uniqid();

        foreach (array_keys(self::SIMULATION_STATE) as $key) {
            $path = $root.'/'.str_replace('.', '-', $key).'.state';

            $this->simulationPaths[$key] = $path;

            config()->set($key, $path);
        }

        $this->fleetPath = $this->simulationPaths['compute.fake.state_path'];
    }

    /**
     * The four conditions under which emptying a whole schema is acceptable.
     *
     * `truncate <every table> cascade` is the most destructive statement in
     * this repository, and it is run automatically after every test in two
     * directories. What makes that safe is not that the code is careful; it is
     * that it refuses to run anywhere but against a database whose only
     * purpose is to be thrown away.
     *
     * All four must hold, and the reason each one is not enough alone:
     *
     *  - **The application environment is `testing`.** Necessary, and nowhere
     *    near sufficient: `APP_ENV` is one environment variable, and a
     *    developer running `APP_ENV=testing` against a populated database is
     *    exactly the accident this guards.
     *  - **The connection is the harness's own.** A test that changed the
     *    default connection — which {@see outsideTheTransaction()} does, and
     *    the concurrency tests do too — must not be able to point this at
     *    whatever was left set.
     *  - **The database is the configured test database.** `phpunit.xml` names
     *    it, so this compares against the same source PHPUnit reads rather
     *    than against a pattern this class invented.
     *  - **The name says it is a test database.** The one that distrusts the
     *    configuration rather than the connection: `.env.testing` is a file
     *    people copy, and a copy that was never repointed satisfies all three
     *    conditions above while naming a database full of real rows. The
     *    browser suite already insists on this for its own database before it
     *    seeds fixed fixtures.
     *
     * A refusal is a `RuntimeException` and not a skip. A harness that cannot
     * establish where it is must fail loudly in the one place the cause is
     * visible; a quiet skip would leave the leak this teardown exists to
     * prevent and report nothing.
     */
    private function refuseToTruncateAnythingButATestDatabase(ConnectionInterface $connection): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException(sprintf(
                'The worker harness refuses to empty a database outside the testing environment (it is "%s").',
                (string) app()->environment(),
            ));
        }

        if ($connection->getName() !== self::CONNECTION) {
            throw new RuntimeException(sprintf(
                'The worker harness refuses to empty anything but its own connection (it was handed "%s").',
                (string) $connection->getName(),
            ));
        }

        $target = (string) $connection->getDatabaseName();
        $expected = (string) config('database.connections.pgsql.database');

        if ($target === '' || $target !== $expected) {
            throw new RuntimeException(sprintf(
                'The worker harness refuses to empty "%s": the configured test database is "%s".',
                $target,
                $expected,
            ));
        }

        /*
         * And the name has to say what the database is for.
         *
         * A different question from the one above, which compares the
         * connection against the test configuration: this one distrusts the
         * test configuration itself. `.env.testing` is a file somebody copies,
         * and a copy that was never repointed names a database full of real
         * rows — at which point conditions one to three all hold and the
         * schema is emptied anyway.
         *
         * The same idiom the browser suite already uses, which insists its own
         * database is named for what it is before it seeds fixed fixtures into
         * it.
         */
        if (! str_contains(strtolower($target), 'test')) {
            throw new RuntimeException(sprintf(
                'The worker harness refuses to empty "%s": a database it may empty has to be named as a test database.',
                $target,
            ));
        }
    }

    /**
     * Leaves the committed database as empty as an untouched one.
     *
     * The first version of this teardown remembered every model the test
     * created and deleted them in reverse. That can only ever clean up what
     * *this process* wrote, and the entire point of the harness is that a
     * second process does the work: a worker running
     * `ProvisionOrderedService` writes a provisioning job, a virtual machine,
     * an assignment, an operation and an audit trail that no `created` event
     * in this process ever announced. Bulk inserts have the same problem —
     * `SeedSubnetAddresses` writes addresses with `insertOrIgnore`, which goes
     * round Eloquent entirely.
     *
     * Those rows are not merely untidy. They are committed, so they outlive
     * the test, and RefreshDatabase's transaction hides them from nobody: the
     * next test in the run that asks a global question — `assertSame(0,
     * ProvisioningJob::query()->count())`, or `->sole()` — sees this test's
     * estate and fails for a reason that has nothing to do with what it is
     * testing. That is how a golden path in this directory came to fail
     * twenty-nine assertions in `tests/Feature/Vps`.
     *
     * So the harness does not try to remember. It owns the committed database
     * for the length of one test and hands it back empty. `migrations` is the
     * one table left alone, because emptying it would tell the next
     * `migrate` that this schema does not exist.
     */
    private function emptyTheCommittedDatabase(): void
    {
        $connection = DB::connection(self::CONNECTION);

        $this->refuseToTruncateAnythingButATestDatabase($connection);

        /** @var list<object{tablename: string}> $rows */
        $rows = $connection->select(
            "select tablename from pg_tables where schemaname = current_schema() and tablename <> 'migrations'",
        );

        if ($rows === []) {
            return;
        }

        /*
         * Not wrapped in a try/catch. A teardown that cannot empty the
         * database has to say so here, where the cause is still on screen —
         * swallowing it would put the leak back and move the failure to
         * whichever unrelated test asked the next global question.
         */
        $connection->statement(
            'truncate '.implode(', ', array_map(
                static fn (object $row): string => '"'.str_replace('"', '""', $row->tablename).'"',
                $rows,
            )).' cascade',
        );
    }

    protected function tearDown(): void
    {
        $this->emptyTheCommittedDatabase();

        foreach ($this->simulationPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if ($this->simulationPaths !== []) {
            @rmdir(dirname((string) reset($this->simulationPaths)));
        }

        $this->simulationPaths = [];

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

        try {
            return $build();
        } finally {
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
                'REDIS_DB' => (string) static::redisDatabase(),
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
                // The same providers, so the worker's hypervisor has heard of
                // the machines this test created — and its panel of the
                // accounts, its datastore of the archives, its zone of the
                // records, and its registrar of the names.
                ...$this->simulationEnvironment(),
            ],
            null,
            $timeoutSeconds,
        );
    }

    /**
     * Runs an artisan command in its own process, with this test's database,
     * queue and controlled providers.
     *
     * The same environment the worker gets, because a scheduled command is the
     * other kind of process a workflow crosses: the poller that confirms what
     * a worker built, the reconciler that compares the platform with a panel.
     * A command run without it gets providers that have never heard of
     * anything this test arranged.
     */
    protected function runArtisan(string $command, int $timeoutSeconds = 120): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', $command, '--no-interaction'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'QUEUE_CONNECTION' => 'redis',
                'REDIS_DB' => (string) static::redisDatabase(),
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
                'MAIL_MAILER' => 'array',
                ...$this->simulationEnvironment(),
            ],
            null,
            $timeoutSeconds,
        );

        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'artisan '.$command.' failed: '.$process->getErrorOutput().$process->getOutput(),
        );

        return $process;
    }

    /**
     * The state paths, as environment variables for another process.
     *
     * @return array<string, string>
     */
    protected function simulationEnvironment(): array
    {
        $environment = [];

        foreach (self::SIMULATION_STATE as $key => $variable) {
            $environment[$variable] = $this->simulationPaths[$key] ?? '';
        }

        return $environment;
    }

    /**
     * The file one family is using, for a test that wants to assert on it or
     * hand it to a process of its own.
     */
    protected function simulationPath(string $configKey): string
    {
        return $this->simulationPaths[$configKey] ?? '';
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

        // An installable image staged on the cluster. A create job with no
        // image is refused permanently — a machine built from nothing boots to
        // a firmware prompt — so the fixture carries one, as a purchase does.
        $template = $this->committed(VmTemplate::factory()->make(['cluster_id' => $cluster->id]));

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
                'template_id' => (string) $template->getKey(),
                'template_reference' => (string) $template->provider_reference,
                'os_family' => $template->os_family->value,
                'architecture' => $template->architecture->value,
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
