<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Application\Jobs\ReconcileCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A separate process, a real Redis queue, and a job that only a worker can
 * finish.
 *
 * Every queue assertion in this repository until now ran on the `sync` driver,
 * where "dispatching" a job means calling it — which proves the job's code and
 * nothing about the queue. The platform's provisioning path is entirely queue
 * work, and it had never been executed by a worker.
 *
 * What makes this a real test and not a fixture:
 *
 *  - The rows are **committed**. RefreshDatabase's transaction is invisible to
 *    another process, so a worker started against it would find no job at all.
 *    Everything here is written for real on a side connection and removed again
 *    in the teardown.
 *  - The queue is **Redis**, on a queue name unique to the run, so a worker
 *    started by one test cannot eat another's message — or a developer's.
 *  - The worker is **`php artisan queue:work` in its own process**. It shares no
 *    memory, no container and no database transaction with this test; the only
 *    thing it is given is the queue to read.
 */
final class ARealWorkerConsumesTheQueueTest extends TestCase
{
    private const string CONNECTION = 'queue_test';

    /** The queue RunProvisioningJob puts itself on. */
    private const string QUEUE = 'provisioning';

    /** Reserved for this suite, and emptied before every test in it. */
    private const int REDIS_DATABASE = 15;

    /** @var list<string> */
    private array $customerIds = [];

    /** @var list<string> */
    private array $clusterIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisIsReachable()) {
            $this->markTestSkipped('This test needs a real Redis; there is none on this machine.');
        }

        config()->set('database.connections.'.self::CONNECTION, config('database.connections.pgsql'));

        /*
         * Isolation is by Redis database index, not by queue name: the jobs pin
         * their own queue — RunProvisioningJob puts itself on `provisioning`,
         * which is the behaviour under test — so the queue name cannot be made
         * unique. Index 15 is emptied before each test and belongs to this
         * suite, so a developer's queue and a previous run's leftovers are both
         * out of the way.
         */
        config()->set('database.redis.default.database', self::REDIS_DATABASE);

        /*
         * The Redis manager reads its configuration once, when the container
         * builds it — which the test case has already done by the time setUp
         * runs. Changing the config afterwards changes nothing until the
         * manager is rebuilt, and the first version of this test spent its
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
    }

    protected function tearDown(): void
    {
        foreach ($this->customerIds as $id) {
            Customer::on(self::CONNECTION)->whereKey($id)->forceDelete();
        }

        foreach ($this->clusterIds as $id) {
            ComputeCluster::on(self::CONNECTION)->whereKey($id)->delete();
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
     * How many messages are waiting on the queue.
     *
     * Read through the same connection the application pushes with, so the
     * prefix is applied once and by the same code.
     */
    private function queued(): int
    {
        return (int) Redis::connection()->llen('queues:'.self::QUEUE);
    }

    private function redisIsReachable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[Test]
    public function a_provider_refusal_is_recorded_and_rescheduled_rather_than_lost(): void
    {
        /*
         * The unit tests prove the engine classifies a refusal correctly. This
         * proves the same thing survives the trip through Redis and a separate
         * process.
         *
         * The refusal arrives before the provider accepted anything, so it is
         * transient: the machine was never created and asking again is safe.
         * What the platform must not do is lose the work — the customer has
         * paid and is waiting — and must not build anything from the failed
         * attempt.
         */
        $job = $this->committedWork(
            FakeComputeProvider::failingHostname('worker-refused'),
        );

        RunProvisioningJob::dispatch((string) $job->getKey());

        $worker = $this->runWorker();

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());

        $settled = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        // Queued again, with the attempt counted and the provider's own words
        // kept: "it failed" is not an answer an operator can act on.
        $this->assertSame(ProvisioningJobStatus::Queued, $settled->status);
        $this->assertSame(FailureClass::Transient, $settled->failure_class);
        $this->assertSame(1, $settled->attempts);
        $this->assertNotNull($settled->last_error);
        $this->assertNotNull($settled->next_attempt_at);

        // Nothing half-built. A refusal that left a machine row behind would
        // bill a customer for something that does not exist.
        $this->assertSame(
            0,
            VirtualMachine::on(self::CONNECTION)->where('service_id', $settled->service_id)->count(),
        );

        // The retry is waiting on its backoff rather than sitting ready: a
        // provider that just refused is not going to answer differently a
        // millisecond later.
        $this->assertSame(0, (int) Redis::connection()->llen('queues:'.self::QUEUE));
        $this->assertGreaterThan(0, (int) Redis::connection()->zcard('queues:'.self::QUEUE.':delayed'));

        // Nothing reached Laravel's failed-jobs table: the engine handled this
        // itself. A row there would mean the platform had given up on work a
        // customer is waiting for without recording why in its own tables.
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('failed_jobs')->count());
    }

    #[Test]
    public function a_worker_killed_mid_build_does_not_produce_a_second_machine(): void
    {
        /*
         * The failure a platform cannot test any other way: the process holding
         * a customer's build disappears — an OOM kill, a node drained, a deploy
         * — after the hypervisor has been asked to create the machine.
         *
         * What must not happen is a second worker starting a second build. The
         * engine's defence is that it claims the job before calling anybody and
         * writes the remote identifier before the handler returns, so a job
         * found in `running` is never picked up again by the claim.
         */
        config()->set('compute.fake.task_delay_seconds', 0);

        $job = $this->committedWork();

        RunProvisioningJob::dispatch((string) $job->getKey());

        $worker = $this->startWorker();

        // Wait for the job to be claimed, then kill the process holding it —
        // without the grace period a normal stop would give it.
        $this->waitUntil(function () use ($job): bool {
            return ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey())->status
                !== ProvisioningJobStatus::Queued;
        });

        $worker->signal(SIGKILL);
        $worker->wait();

        $afterTheKill = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());
        $machinesAfterTheKill = VirtualMachine::on(self::CONNECTION)
            ->where('service_id', $afterTheKill->service_id)
            ->count();

        // A second worker comes along, as one really would: the job may still
        // be on the queue, or Redis may hand it back when the reservation
        // expires.
        RunProvisioningJob::dispatch((string) $job->getKey());

        $second = $this->runWorker();
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());

        $finalMachines = VirtualMachine::on(self::CONNECTION)
            ->where('service_id', $afterTheKill->service_id)
            ->count();

        // The property that matters: the second worker added nothing. Whether
        // the killed process had got as far as creating the row is a race and
        // is not asserted; that it cannot be created twice is not.
        $this->assertSame(
            $machinesAfterTheKill,
            $finalMachines,
            'A worker that died mid-build let a second worker build the customer another machine.',
        );

        $this->assertLessThanOrEqual(
            1,
            $finalMachines,
            'One order, one machine — whatever happened to the process that was building it.',
        );

        /*
         * And the job is not quietly back in the queue pretending to be fresh.
         * A job the engine claimed stays claimed until something settles it:
         * the stale-job detector, which runs every five minutes, is what
         * eventually escalates this to a person. That is the intended outcome
         * for a build nobody can prove finished.
         */
        $this->assertNotSame(
            ProvisioningJobStatus::Queued,
            ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey())->status,
        );
    }

    /**
     * Starts a worker without waiting for it, so the test can kill it.
     */
    private function startWorker(): Process
    {
        $worker = $this->buildWorker(120, self::QUEUE);

        $worker->start();

        return $worker;
    }

    /**
     * Polls until the condition holds, or fails the test.
     *
     * A fixed sleep would be either flaky or slow; the job is claimed in
     * milliseconds on a warm machine and in a second or two on a cold one.
     */
    private function waitUntil(callable $condition, float $timeoutSeconds = 30.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition() === true) {
                return;
            }

            usleep(50_000);
        }

        $this->fail('The worker never reached the state this test needed to interrupt.');
    }

    /**
     * A committed customer, cluster, node, pool, service and queued job: what a
     * paid order leaves behind for a worker to act on.
     */
    private function committedWork(?string $hostname = null): ProvisioningJob
    {
        $customer = Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW']);
        $customer->setConnection(self::CONNECTION)->save();
        $this->customerIds[] = (string) $customer->getKey();

        $cluster = ComputeCluster::factory()->make(['status' => 'active']);
        $cluster->setConnection(self::CONNECTION)->save();

        $node = ComputeNode::factory()->withCapacity(64, 262_144, 4_000)->make(['cluster_id' => $cluster->id]);
        $node->setConnection(self::CONNECTION)->save();

        // Without storage of the requested class the scheduler correctly
        // refuses to place anything, and the worker's first run reported
        // exactly that — which is how this fixture came to be complete.
        $storage = ComputeStorage::factory()->available(4_000)->make([
            'cluster_id' => $cluster->id,
            'node_id' => $node->id,
        ]);
        $storage->setConnection(self::CONNECTION)->save();

        $pool = IpPool::factory()->make(['is_active' => true, 'ip_version' => 4]);
        $pool->setConnection(self::CONNECTION)->save();

        // A pool with no addresses in it allocates nothing, and the worker said
        // so on its second run. The subnet is seeded through the platform's own
        // action so the address rows are exactly what allocation expects.
        // A customer-facing network with a bridge: the handler refuses to
        // attach a machine to a segment it cannot name, which is what its third
        // run reported.
        $network = Network::factory()->make(['bridge' => 'vmbr1', 'vlan_id' => 1234]);
        $network->setConnection(self::CONNECTION)->save();

        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
            ->make(['ip_pool_id' => $pool->id, 'network_id' => $network->id]);
        $subnet->setConnection(self::CONNECTION)->save();

        DB::setDefaultConnection(self::CONNECTION);

        try {
            app(SeedSubnetAddresses::class)->execute($subnet);
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        $service = Service::factory()->make([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]);
        $service->setConnection(self::CONNECTION)->save();

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
        $job->setConnection(self::CONNECTION)->save();

        return $job;
    }

    /**
     * Runs a real worker until the queue is empty, and returns its output.
     */
    private function runWorker(int $timeoutSeconds = 120, string $queue = self::QUEUE): Process
    {
        $worker = $this->buildWorker($timeoutSeconds, $queue);

        $worker->run();

        return $worker;
    }

    private function buildWorker(int $timeoutSeconds, string $queue): Process
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
            ],
            null,
            $timeoutSeconds,
        );
    }

    #[Test]
    public function a_worker_in_another_process_builds_the_machine(): void
    {
        $job = $this->committedWork();

        RunProvisioningJob::dispatch((string) $job->getKey());

        // Nothing has happened yet: dispatching put a message on Redis and
        // returned. This assertion is what separates a queue from a function
        // call.
        $this->assertSame(1, $this->queued());
        $this->assertSame(
            ProvisioningJobStatus::Queued,
            ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey())->status,
        );

        $worker = $this->runWorker();

        $this->assertTrue(
            $worker->isSuccessful(),
            'The worker exited badly: '.$worker->getErrorOutput().$worker->getOutput(),
        );

        $finished = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        $this->assertSame(
            ProvisioningJobStatus::Succeeded,
            $finished->status,
            'A separate worker consumed the message but the job did not finish: '.$worker->getOutput(),
        );

        // And the thing the job existed to create.
        $this->assertSame(
            1,
            DB::connection(self::CONNECTION)->table('virtual_machines')
                ->where('service_id', $finished->service_id)->count(),
        );

        $this->assertSame(
            ServiceStatus::Active,
            Service::on(self::CONNECTION)->findOrFail($finished->service_id)->status,
        );

        $this->assertSame(0, $this->queued());
    }

    #[Test]
    public function a_second_delivery_of_the_same_message_builds_nothing_more(): void
    {
        $job = $this->committedWork();

        RunProvisioningJob::dispatch((string) $job->getKey());
        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->assertSame(2, $this->queued());

        $worker = $this->runWorker();

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());

        // Two messages, one machine. The engine claims the job under a row lock
        // and the second delivery finds it already finished.
        $finished = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        $this->assertSame(
            1,
            DB::connection(self::CONNECTION)->table('virtual_machines')
                ->where('service_id', $finished->service_id)->count(),
        );
    }

    #[Test]
    public function the_scheduler_dispatches_work_that_a_worker_then_does(): void
    {
        /*
         * The chain the platform depends on and had never run end to end:
         * a scheduled command puts a job on Redis, the command returns, and a
         * separate worker picks the job up and changes the database. Until this
         * phase there was no scheduled command that dispatched anything at all.
         */
        $cluster = ComputeCluster::factory()->make(['status' => 'active']);
        $cluster->setConnection(self::CONNECTION)->save();
        $this->clusterIds[] = (string) $cluster->getKey();

        $node = ComputeNode::factory()->withCapacity(8, 16_384, 500)->make([
            'cluster_id' => $cluster->id,
        ]);
        $node->setConnection(self::CONNECTION)->save();

        $this->artisan('infrastructure:reconcile', ['--cluster' => (string) $cluster->getKey()])
            ->assertExitCode(0);

        // Dispatched, not done: the scheduler's process has finished and the
        // work is waiting for somebody else.
        $this->assertSame(1, (int) Redis::connection()->llen('queues:'.ReconcileCluster::QUEUE));

        $this->assertNull($cluster->fresh()->last_synced_at);

        $worker = $this->runWorker(queue: ReconcileCluster::QUEUE);

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());

        $this->assertNotNull(
            ComputeCluster::on(self::CONNECTION)->findOrFail($cluster->getKey())->last_synced_at,
            'A worker consumed the reconciliation job but the cluster was never marked as synced: '
            .$worker->getOutput(),
        );

        // The node the fake hypervisor reports is discovered, and — because
        // discovery is not authorisation — parked in maintenance rather than
        // opened for placement.
        $discovered = ComputeNode::on(self::CONNECTION)
            ->where('cluster_id', $cluster->getKey())
            ->whereKeyNot($node->getKey())
            ->get();

        $this->assertNotEmpty($discovered, 'The reconciliation pass recorded no hardware at all.');
        $this->assertSame(['maintenance'], $discovered->pluck('status')->map(fn ($s) => $s->value)->unique()->all());

        $this->assertSame(0, (int) Redis::connection()->llen('queues:'.ReconcileCluster::QUEUE));
    }
}
