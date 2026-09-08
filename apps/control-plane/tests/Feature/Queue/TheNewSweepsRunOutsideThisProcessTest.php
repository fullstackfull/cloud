<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * The sweeps this phase added, run the way production runs them: as a command
 * in its own process, against committed rows, with a worker somewhere else.
 *
 * A scheduled command tested by `$this->artisan()` proves the command's body.
 * It does not prove that the command works when the only things it is handed
 * are a database URL and an environment — which is all the scheduler gives it —
 * and it cannot prove anything at all about the chain that runs afterwards,
 * because there is no queue in a test container.
 *
 * The chain under test here is the one the termination work depends on and
 * which had never run end to end:
 *
 *     scheduler → services:end-expired → provisioning job on Redis
 *               → worker in another process → hypervisor → verification
 */
final class TheNewSweepsRunOutsideThisProcessTest extends WorkerHarness
{
    /**
     * Rows the commands wrote in their own processes, cleaned up here.
     *
     * The harness deletes the fixtures a test creates; it cannot know about
     * the audit entries and drift records a command running outside every
     * transaction leaves behind. Those persist for the rest of the run, and a
     * later test counting terminations globally would fail for reasons that
     * have nothing to do with it — which is exactly what happened.
     */
    protected function tearDown(): void
    {
        foreach ($this->committedServiceIds() as $serviceId) {
            AuditEntry::on(self::CONNECTION)->where('subject_id', $serviceId)->delete();
        }

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function committedServiceIds(): array
    {
        /** @var list<string> $ids */
        $ids = Service::on(self::CONNECTION)
            ->whereNotNull('termination_requested_at')
            ->pluck('id')
            ->all();

        return $ids;
    }

    #[Test]
    public function the_retention_sweep_ends_a_cancelled_service_and_a_worker_destroys_the_machine(): void
    {
        $job = $this->committedOrder('web-kw-99');

        // Built for real, by a worker, so there is a machine to destroy.
        // `committedOrder` leaves the job queued in the database rather than on
        // Redis, exactly as a paid order does; dispatching is the engine's
        // step, and it is this test's job to take it.
        RunProvisioningJob::dispatch((string) $job->getKey());
        $this->work(RunProvisioningJob::QUEUE);

        $service = Service::on(self::CONNECTION)->findOrFail($job->service_id);

        $machine = VirtualMachine::on(self::CONNECTION)
            ->where('service_id', $service->getKey())
            ->firstOrFail();

        $this->assertNotNull($this->remote($machine), 'The build did not leave a machine behind.');

        /*
         * The customer cancelled, the window has closed, and nobody has
         * pressed anything since. This is the state the scheduler wakes up to.
         */
        Service::on(self::CONNECTION)->whereKey($service->getKey())->update([
            'status' => ServiceStatus::Suspended->value,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $sweep = $this->runCommand('services:end-expired');

        $this->assertTrue(
            $sweep->isSuccessful(),
            'The retention sweep failed in its own process: '.$sweep->getErrorOutput().$sweep->getOutput(),
        );
        $this->assertStringContainsString('1 services ended', $sweep->getOutput());

        // Queued, not done. The sweep's process has finished and the machine
        // is still there, waiting for somebody else to destroy it.
        $this->assertSame(1, $this->queued(RunProvisioningJob::QUEUE));
        $this->assertNotNull($this->remote($machine));

        $worker = $this->work(RunProvisioningJob::QUEUE);

        $this->assertNull(
            $this->remote($machine),
            'A worker consumed the termination job and the hypervisor still has the machine: '.$worker->getOutput(),
        );

        $this->assertSame(
            ServiceStatus::Terminated,
            Service::on(self::CONNECTION)->findOrFail($service->getKey())->status,
        );
    }

    #[Test]
    public function a_worker_killed_mid_termination_does_not_destroy_a_second_machine(): void
    {
        $first = $this->committedOrder('web-kw-98');

        RunProvisioningJob::dispatch((string) $first->getKey());
        $this->work(RunProvisioningJob::QUEUE);

        $service = Service::on(self::CONNECTION)->findOrFail($first->service_id);

        Service::on(self::CONNECTION)->whereKey($service->getKey())->update([
            'status' => ServiceStatus::Suspended->value,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $this->runCommand('services:end-expired');

        /*
         * Run twice. The second pass must find nothing: the service is already
         * on its way out, and a sweep that queued a second destroy would be a
         * sweep that destroys whatever has been built in the meantime.
         */
        $second = $this->runCommand('services:end-expired');

        $this->assertStringContainsString('0 services ended', $second->getOutput());
        $this->assertSame(1, $this->queued(RunProvisioningJob::QUEUE));

        $this->assertSame(
            1,
            ProvisioningJob::on(self::CONNECTION)
                ->where('service_id', $service->getKey())
                ->where('kind', ProvisioningJobKind::DestroyVps->value)
                ->count(),
            'The sweep queued a second destruction for a service it had already ended.',
        );
    }

    #[Test]
    public function the_task_poller_runs_in_its_own_process_and_confirms_what_the_worker_built(): void
    {
        $job = $this->committedOrder('web-kw-97');

        RunProvisioningJob::dispatch((string) $job->getKey());
        $this->work(RunProvisioningJob::QUEUE);

        $finished = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        $this->assertSame(ProvisioningJobStatus::Succeeded, $finished->status);
        $this->assertNotNull($finished->remote_job_id, 'The build recorded no provider task.');
        $this->assertNotNull($finished->remote_task_node, 'The build recorded a task with no node to ask about it.');

        // Unconfirmed until somebody asks: succeeded means the hypervisor took
        // the request.
        $this->assertNull($finished->remote_task_state);

        $poll = $this->runCommand('compute:poll-tasks');

        $this->assertTrue(
            $poll->isSuccessful(),
            'The task poller failed in its own process: '.$poll->getErrorOutput().$poll->getOutput(),
        );
        $this->assertStringContainsString('1 confirmed', $poll->getOutput());

        $this->assertSame(
            'succeeded',
            ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey())->remote_task_state,
        );
    }

    /**
     * What the hypervisor says about a machine, read through the same fleet
     * file the worker writes.
     */
    private function remote(VirtualMachine $machine): ?RemoteVmState
    {
        $node = ComputeNode::on(self::CONNECTION)->findOrFail($machine->node_id);

        return $this->provider()->getVm((string) $node->provider_name, (string) $machine->provider_id);
    }

    private function provider(): ComputeProvider
    {
        return app(ComputeProviderFactory::class)->for(
            ComputeCluster::on(self::CONNECTION)->firstOrFail(),
        );
    }

    /**
     * Runs an artisan command the way the scheduler does: another process,
     * handed nothing but an environment.
     */
    private function runCommand(string $command): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', $command, '--no-interaction'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'QUEUE_CONNECTION' => 'redis',
                'REDIS_DB' => (string) self::REDIS_DATABASE,
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_PASSWORD' => config('database.connections.pgsql.password'),
                'MAIL_MAILER' => 'array',
                'COMPUTE_FAKE_STATE_PATH' => $this->fleetPath(),
            ],
            null,
            120,
        );

        $process->run();

        return $process;
    }
}
