<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Application\Actions\DetectVirtualMachineDrift;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Dns\Application\Jobs\PublishRecord;
use Lynomia\Modules\Dns\Application\Jobs\PublishZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the platform does when a stage does not work, and what it must not do.
 *
 * ---------------------------------------------------------------------------
 * How this file is organised, and why not as one loop
 * ---------------------------------------------------------------------------
 *
 * The brief asks for a table-driven matrix. Half of it is: {@see REQUIRED} is
 * the list of scenarios that must be covered, {@see ELSEWHERE} says which file
 * already covers the ones that were already proved, and
 * {@see every_required_scenario_is_covered_somewhere} fails if a scenario is in
 * neither. That is the part a table does well — completeness.
 *
 * The scenarios themselves are written out. A single parameterised loop over
 * ten faults would need a setup that could produce all ten, which means a
 * fixture builder with ten switches in it, and the failure that mattered would
 * be reported as a line number inside the builder. The expectations are what
 * the table would have held, and they are asserted here in full: the order's
 * state, the money, the service, the provider, what retried, and what was
 * never dispatched at all.
 *
 * ---------------------------------------------------------------------------
 * The rule every test here follows
 * ---------------------------------------------------------------------------
 *
 * A failure at one stage must not be reported as a failure at every stage
 * after it. So each test asserts the state of the stage that failed *and*
 * that the stages below it were never entered — zero machines, zero
 * addresses, zero records — because "no address" and "an address nobody can
 * account for" are different bad days and only one of them is recoverable.
 */
#[Group('golden-path')]
final class TheFailureMatrixTest extends GoldenPathHarness
{
    /**
     * Every scenario this gap is required to cover.
     *
     * @var array<string, string> id => what it means
     */
    public const array REQUIRED = [
        'payment.declined' => 'the gateway refuses the card',
        'payment.duplicate_webhook' => 'the same provider event arrives twice',
        'provision.provider_refuses_before_create' => 'the hypervisor refuses the build outright',
        'provision.partial_create' => 'the call times out and a machine may exist',
        'provision.capacity_exhausted' => 'no node has room',
        'provision.template_missing' => 'the image a plan needs never reaches the build at all',
        'provision.address_exhausted' => 'the pool has no free address',
        'provision.duplicate_delivery' => 'the same message is delivered twice',
        'provision.task_timeout' => 'the provider task never settles',
        'dns.mutation_failed_after_create' => 'the machine exists and its name does not',
        'backup.failed_after_activation' => 'the service is active and its backup is not',
        'backup.verification_failed' => 'the archive exists and cannot be read back',
        'lifecycle.cancellation' => 'a cancelled service is torn down',
        'reconciliation.drift' => 'the provider and the platform disagree',
        'queue.retry' => 'a transient failure is rescheduled rather than lost',
        'provider.duplicate_terminal_result' => 'the same completion is polled twice',
        'event.out_of_order' => 'a completion arrives after a cancellation',
        'production.controlled_driver_refused' => 'a controlled driver cannot run in production',
    ];

    /**
     * Scenarios proved before this gap, and where.
     *
     * Named rather than repeated. A second test of the same behaviour is not
     * more evidence; it is another thing to keep in step.
     *
     * @var array<string, string> id => the test that proves it
     */
    public const array ELSEWHERE = [
        'payment.declined' => 'TheVpsGoldenPathTest::a_declined_payment_leaves_the_order_unpaid_and_builds_nothing',
        'payment.duplicate_webhook' => 'TheVpsGoldenPathTest::a_redelivered_webhook_produces_one_payment_one_service_and_one_machine',
        'provision.address_exhausted' => 'Tests\\Feature\\Ipam\\IpAllocationTest — an exhausted pool is a capacity failure, not a permanent one',
        'provision.duplicate_delivery' => 'Tests\\Feature\\Queue\\ARealWorkerConsumesTheQueueTest::a_second_delivery_of_the_same_message_builds_nothing_more',
        'lifecycle.cancellation' => 'Tests\\Feature\\Queue\\TheNewSweepsRunOutsideThisProcessTest::the_retention_sweep_ends_a_cancelled_service_and_a_worker_destroys_the_machine',
        'queue.retry' => 'Tests\\Feature\\Queue\\ARealWorkerConsumesTheQueueTest::a_provider_refusal_is_recorded_and_rescheduled_rather_than_lost',
        'production.controlled_driver_refused' => 'Tests\\Feature\\Simulation\\NoControlledDriverSurvivesProductionTest — four controls per driver',
    ];

    /**
     * Scenarios covered by a test in this file.
     *
     * @var list<string>
     */
    public const array HERE = [
        'provision.provider_refuses_before_create',
        'provision.partial_create',
        'provision.capacity_exhausted',
        'provision.template_missing',
        'provision.task_timeout',
        'dns.mutation_failed_after_create',
        'backup.failed_after_activation',
        'backup.verification_failed',
        'reconciliation.drift',
        'provider.duplicate_terminal_result',
        'event.out_of_order',
    ];

    #[Test]
    public function every_required_scenario_is_covered_somewhere(): void
    {
        $covered = array_unique([...self::HERE, ...array_keys(self::ELSEWHERE)]);

        $missing = array_diff(array_keys(self::REQUIRED), $covered);

        $this->assertSame([], array_values($missing), 'a required failure scenario is covered by nothing');

        // And the other direction: a claim in this file about a test that has
        // been renamed or deleted is worse than no claim at all.
        $unknown = array_diff($covered, array_keys(self::REQUIRED));

        $this->assertSame([], array_values($unknown), 'a scenario is covered here and is not in the required list');
    }

    #[Test]
    public function a_hypervisor_that_refuses_the_build_is_transient_and_the_job_waits_rather_than_dying(): void
    {
        $estate = $this->committedVpsEstate();

        [$service, $job] = $this->committedBuild('provider-fail-one', estate: $estate);

        $this->work(RunProvisioningJob::QUEUE);

        $failed = $job->fresh();

        /*
         * Back to `queued`, classified `transient`, with the attempt recorded.
         * That is the platform's own reading of a hypervisor that said no: a
         * cluster refusing a build at this second is usually a cluster that
         * will accept it in five minutes, and a customer whose purchase was
         * marked failed because a node was busy has been told something
         * untrue.
         *
         * It is also why this test does not assert `failed`: the classification
         * is the decision, and the status follows it.
         */
        $this->assertSame(ProvisioningJobStatus::Queued, $failed?->status);
        $this->assertSame('transient', $failed?->failure_class?->value);
        $this->assertGreaterThanOrEqual(1, $failed->attemptRecords()->count());

        // And nothing below the refusal was entered.
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(0, IpAssignment::query()->where('service_id', $service->getKey())->count());
        $this->assertNotSame(ServiceStatus::Active, $service->fresh()?->status);

        /*
         * §85, and the invariant nothing was asserting: a retried build must
         * not take a second address.
         *
         * No assignment is made on the way to the failure — the handler
         * commits one only against a machine that exists — so counting
         * assignments proves nothing about the pool. What can leak is the
         * reservation. A transient refusal deliberately keeps it: the job is
         * going to be retried and the retry must land on the same address, so
         * compensation runs only where a job has stopped for good. That makes
         * the allocator's own lookup of what this job already holds the only
         * thing standing between a retry and a stranded address — and a
         * stranded reservation is released by nothing, because the reaper
         * only collects reservations whose job reached a terminal failure.
         *
         * So: one reservation after the refusal, and still one after the
         * second attempt, with no address reserved that no reservation names.
         */
        $this->assertSame(1, $this->liveReservationCount($job), 'the refused build released the address it will need again');

        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(
            1,
            $this->liveReservationCount($job),
            'the second attempt took a second address and stranded the first',
        );

        $this->assertSame(
            1,
            IpAddress::query()
                ->whereHas(
                    'subnet',
                    static fn ($query) => $query->where('ip_pool_id', $estate['pool']->getKey()),
                )
                ->where('status', IpAddressStatus::Reserved->value)
                ->count(),
            'the pool holds an address reserved for nothing',
        );

        $this->assertSame(0, IpAssignment::query()->where('service_id', $service->getKey())->count());
    }

    #[Test]
    public function a_build_that_timed_out_goes_to_review_and_is_never_retried_into_a_second_machine(): void
    {
        /*
         * The Timeout Rule. The call did not answer, so whether a machine
         * exists is unknown — and the one thing the platform must not do is
         * decide by trying again. `needs_review` is a person's queue, not a
         * failure.
         */
        [$service, $job] = $this->committedBuild('timeout-one');

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->fresh()?->status);

        // Delivered again, as a queue is allowed to. Still one review, still
        // no second machine.
        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->fresh()?->status);
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());
        $this->assertNotSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    #[Test]
    public function a_fleet_with_no_room_is_a_capacity_failure_that_waits_rather_than_a_refund(): void
    {
        /*
         * Capacity resolves: an operator adds a node, a machine is terminated,
         * a disk is grown. A customer who would happily have waited an hour
         * must not be told their purchase failed.
         */
        [$service, $job] = $this->committedBuild('no-room', resources: [
            'vcpu' => 512,
            'memory_mib' => 4_194_304,
            'disk_gib' => 100_000,
        ]);

        $this->work(RunProvisioningJob::QUEUE);

        $fresh = $job->fresh();

        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $fresh?->status);
        $this->assertSame('capacity', $fresh?->failure_class?->value);
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());
    }

    #[Test]
    public function a_purchased_machine_is_built_with_no_image_at_all_and_that_is_a_carried_gap(): void
    {
        /*
         * ---------------------------------------------------------------------
         * A finding, not a scenario that behaves as intended
         * ---------------------------------------------------------------------
         *
         * The brief asks what happens when the image a plan needs is missing.
         * The answer this gap found is stranger: the build never asks for one.
         *
         *  - the catalogue holds installable images per cluster, and preflight
         *    checks them (`mapping.template`, asserted in the VPS gate);
         *  - the reinstall path resolves one and refuses without it;
         *  - `ProvisionOrderedService` puts `vcpu`, `memory_mib`, `disk_gib`,
         *    the cluster, the pool, the storage class and a hostname into the
         *    payload, and no template;
         *  - `CreateVpsHandler` reads `template_reference` from the payload and
         *    passes null when it is absent;
         *  - `ProxmoxComputeProvider` omits `import-from` for a null reference,
         *    which creates the disk and imports nothing.
         *
         * So a customer's machine is built with an empty disk. Every half of
         * this works; the workflow never joins them, which is exactly the kind
         * of gap a phase about workflows exists to find.
         *
         * This test asserts today's behaviour, and its name says that is the
         * gap. A fix is a product decision — which image a plan implies is a
         * catalogue relationship that does not exist yet — so it is recorded
         * and carried to Gap 8 rather than invented here.
         */
        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'matrix-no-image');

        $this->payThroughTheProvider($invoice, $customer, 'pi_matrix_no_image');

        $this->work('payments');
        $this->work(RunProvisioningJob::QUEUE);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();
        $job = ProvisioningJob::query()->where('service_id', $service->getKey())->sole();

        // The build succeeded, which is the problem.
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        $this->assertArrayNotHasKey(
            'template_reference',
            (array) $job->payload,
            'a template reached the payload — the carried gap has been closed, update this test',
        );

        /*
         * And the hypervisor's own record of what it installed: nothing. The
         * controlled provider records the image it was given under the key the
         * reinstall uses, which is what makes the absence observable at all.
         */
        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        $remote = app(ComputeProviderFactory::class)
            ->for($estate['cluster'])
            ->getVm($estate['node']->provider_name, (string) $machine->provider_id);

        $this->assertNotNull($remote);
        $this->assertNull($remote->raw['installed_template'] ?? null);
    }

    #[Test]
    public function a_task_that_never_settles_is_left_for_a_person_rather_than_declared_finished(): void
    {
        [$service, $job] = $this->committedBuild('task-fail-one');

        $this->work(RunProvisioningJob::QUEUE);

        /*
         * The create was *accepted*: the hypervisor took the request and
         * handed back a task, so the worker's part is done and the job is
         * waiting on a task rather than on a call. That is the shape of every
         * asynchronous provider, and the poller is the thing that finds out.
         */
        $this->runArtisan('compute:poll-tasks');

        /*
         * A task that reported failure is a different day from a refusal: the
         * hypervisor may have got part of the way, so the platform records
         * what it knows and stops rather than building again.
         */
        $settled = $job->fresh();

        $this->assertContains(
            $settled?->status,
            [ProvisioningJobStatus::Failed, ProvisioningJobStatus::NeedsReview],
        );

        $this->assertNotNull($settled->last_error, 'the task failed and nothing says why');

        /*
         * A finding, recorded rather than asserted away: the service is left
         * ACTIVE. The create was accepted, the handler wrote the machine and
         * activated the service, and the later task failure moves the job and
         * not the service. Whether an active service whose build failed should
         * be demoted, or whether the operation record is the right place for
         * that news, is a product decision — it is carried to Gap 8 in §33 of
         * the report rather than decided in a test.
         */
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    #[Test]
    public function a_name_that_cannot_be_published_does_not_take_the_machine_with_it(): void
    {
        /*
         * §40, partial success. The machine exists and the customer has it;
         * the record does not, and the platform says which of the two is
         * wrong rather than marking the whole purchase failed.
         */
        [$zone, $record] = $this->outsideTheTransaction(function (): array {
            $customer = $this->committedCustomer();

            $zone = DnsZone::factory()->create([
                'customer_id' => $customer->getKey(),
                'name' => 'matrix-dns.test',
                'state' => DnsState::Pending,
                'provider' => 'fake',
            ]);

            // The marker the controlled DNS provider refuses by name.
            $record = DnsRecord::factory()->create([
                'dns_zone_id' => $zone->getKey(),
                'name' => 'dns-refused.matrix-dns.test',
                'state' => DnsState::Pending,
            ]);

            return [$zone, $record];
        });

        PublishZone::dispatch((string) $zone->getKey());

        $this->work('default');

        $this->assertSame(DnsState::Active, $zone->fresh()?->state);

        PublishRecord::dispatch((string) $record->getKey());

        $this->work('default');

        $failed = $record->fresh();

        $this->assertSame(DnsState::Failed, $failed?->state);
        $this->assertNotNull($failed->failure_reason);

        // The provider's message is kept for an operator and redacted first:
        // the controlled provider quotes the request it sent, credentials and
        // all, exactly as a real zone client does.
        $this->assertStringNotContainsString('fake-cloudflare-token', (string) $failed->failure_reason);

        // And the zone is untouched by the record's failure.
        $this->assertSame(DnsState::Active, $zone->fresh()?->state);
    }

    #[Test]
    public function a_backup_that_fails_leaves_the_service_active_and_says_so_on_the_backup(): void
    {
        $backup = $this->outsideTheTransaction(function (): Backup {
            $customer = $this->committedCustomer();
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);
            $node = ComputeNode::factory()->withCapacity(32, 65_536, 2_000)->create([
                'cluster_id' => $cluster->getKey(),
            ]);

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Active,
            ]);

            $machine = VirtualMachine::factory()
                ->onNode($node)
                ->forService($service)
                ->resources(2, 2048, 20)
                ->create();

            config()->set('backups.datastores.'.$cluster->slug, 'pbs-test-01');

            // The notes carry the marker the controlled datastore fails on.
            return app(RequestServiceBackup::class)->execute(
                $machine,
                notes: 'backup-fails: the marker the controlled datastore refuses on',
            );
        });

        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Failed, $settled?->state);
        $this->assertNotNull($settled->failure_reason);

        // The service is untouched. A failed backup is a failed backup.
        $this->assertSame(
            ServiceStatus::Active,
            Service::query()->whereKey($settled->service_id)->sole()->status,
        );
    }

    #[Test]
    public function a_provider_that_disagrees_with_the_platform_produces_drift_and_no_automatic_repair(): void
    {
        $estate = $this->committedVpsEstate();

        [$service, $job] = $this->committedBuild('drift-one', estate: $estate);

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        /*
         * The machine is stopped at the hypervisor without the platform being
         * told — an operator on the console, a host that rebooted, a crash.
         */
        $provider = new FakeComputeProvider;
        $provider->stopVm($estate['node']->provider_name, (string) $machine->provider_id);

        $recorded = app(DetectVirtualMachineDrift::class)->execute($estate['cluster']);

        $this->assertGreaterThan(0, $recorded);

        $drift = ResourceDrift::query()
            ->where('resource_type', 'virtual_machine')
            ->where('provider_reference', (string) $machine->provider_id)
            ->first();

        $this->assertNotNull($drift, 'the disagreement was not recorded anywhere');

        // Recorded, not repaired. Every automated remedy for drift is one bug
        // away from destroying a customer's machine, so the platform records
        // and waits for a person.
        $this->assertSame('open', $drift->status->value);
    }

    #[Test]
    public function the_same_completion_polled_twice_confirms_one_build(): void
    {
        $estate = $this->committedVpsEstate();

        [$service, $job] = $this->committedBuild('double-poll', estate: $estate);

        $this->work(RunProvisioningJob::QUEUE);

        $before = VirtualMachine::query()->where('service_id', $service->getKey())->count();

        app(PollProviderTasks::class)->execute();
        app(PollProviderTasks::class)->execute();

        $this->assertSame($before, VirtualMachine::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    #[Test]
    public function a_completion_that_arrives_after_a_cancellation_does_not_resurrect_it(): void
    {
        /*
         * §39. The order of two messages is not something a queue promises, so
         * the platform's state machine has to be the thing that refuses —
         * which is exactly what it does: a cancelled job is terminal, and a
         * terminal job does not go back to running because a late success
         * turned up.
         */
        [$service, $job] = $this->committedBuild('late-success');

        $this->outsideTheTransaction(function () use ($job): void {
            $job->forceFill([
                'status' => ProvisioningJobStatus::Cancelled,
                'finished_at' => now(),
            ])->save();
        });

        // The message that was already on the queue when the cancellation
        // happened, delivered afterwards.
        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::Cancelled, $job->fresh()?->status);
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());
        $this->assertNotSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    #[Test]
    public function a_verification_that_failed_is_not_a_verified_archive(): void
    {
        /*
         * §41, and the row this matrix was crediting to the wrong test. The
         * entry used to point at the simulator-contract test, which proves
         * that the controlled datastore can report a verification that failed
         * — a fact about the simulator, not about the platform. What the
         * platform does with that answer is this, and nothing asserted it.
         *
         * It matters because of what the three values mean: `verified` is
         * null when nobody has checked, true when the archive was read back,
         * and false-with-a-reason when it could not be. A platform that let a
         * failed verification land as `Verified` would tell a customer their
         * backup restores because the check ran, which is the one thing worse
         * than not checking.
         *
         * A carried gap goes with it, in §33 of the report: nothing in the
         * platform starts a verification. `startVerification` exists on the
         * provider contract and on both drivers, `Verifying` is a state with
         * transitions out of it, and no action, job or command ever puts a row
         * into it. So the row below is put into `Verifying` here, and this
         * test covers the half that exists — the verdict — while
         * `EveryBackupAlertMetricHasAProducerTest` covers the consequence of
         * the half that does not: a finished backup is never reported as a
         * verified one.
         */
        [$backup] = $this->committedVerification(FakeBackupProvider::FAILING_MARKER);

        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Failed, $settled?->state);
        $this->assertNotNull($settled->failure_reason, 'the archive could not be read back and nothing says so');

        // Not verified, and not silently left as though it had been.
        $this->assertNotTrue($settled->verified);
        $this->assertNull($settled->verified_at);
    }

    #[Test]
    public function a_verification_that_passed_is_a_verified_archive(): void
    {
        // §100. The same path with the marker taken out: the state machine
        // and the poller are the same, and the verdict is the opposite one.
        [$backup] = $this->committedVerification();

        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Verified, $settled?->state);
        $this->assertTrue($settled->verified);
        $this->assertNotNull($settled->verified_at);
        $this->assertNull($settled->failure_reason);
    }

    #[Test]
    public function a_backup_nobody_verified_is_never_reported_as_verified(): void
    {
        /*
         * The other half of the three-valued answer, and the one nothing
         * asserted: an ordinary backup that finished. It is `Succeeded`,
         * which means the datastore wrote it; `verified` stays null, which
         * means nobody has read it back. Folding those together is how a
         * platform comes to tell a customer their backups are fine on the
         * strength of never having checked — so the null is asserted here as
         * a value, not treated as the absence of one.
         */
        $backup = $this->outsideTheTransaction(function (): Backup {
            $customer = $this->committedCustomer();
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);
            $node = ComputeNode::factory()->withCapacity(32, 65_536, 2_000)->create([
                'cluster_id' => $cluster->getKey(),
            ]);

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Active,
            ]);

            $machine = VirtualMachine::factory()
                ->onNode($node)
                ->forService($service)
                ->resources(2, 2048, 20)
                ->create();

            config()->set('backups.datastores.'.$cluster->slug, 'pbs-test-01');

            return app(RequestServiceBackup::class)->execute($machine, notes: 'the nightly one');
        });

        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Succeeded, $settled?->state);
        $this->assertNull($settled->verified, 'a backup nobody read back is reported as one that was');
        $this->assertNull($settled->verified_at);
    }

    /**
     * A committed service and a queued build for it, with the hostname the
     * caller wants — which is how a fault is chosen, because the controlled
     * hypervisor reads its markers from the name it is given.
     *
     * @param  array<string, mixed>|null  $resources
     * @param  array{cluster: ComputeCluster, node: ComputeNode, pool: IpPool, template: VmTemplate}|null  $estate
     * @return array{0: Service, 1: ProvisioningJob}
     */
    private function committedBuild(string $hostname, ?array $resources = null, ?array $estate = null): array
    {
        $estate ??= $this->committedVpsEstate();

        return $this->outsideTheTransaction(function () use ($hostname, $resources, $estate): array {
            $customer = $this->committedCustomer();

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Provisioning,
            ]);

            $job = app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::CreateVps,
                idempotencyKey: 'matrix:'.$hostname.':'.$service->getKey(),
                provider: $estate['cluster']->driver->value,
                serviceId: (string) $service->getKey(),
                customerId: (string) $customer->getKey(),
                payload: [
                    'cluster_id' => (string) $estate['cluster']->getKey(),
                    'ip_pool_id' => (string) $estate['pool']->getKey(),
                    'storage_class' => 'nvme',
                    'hostname' => $hostname,
                    ...($resources ?? ['vcpu' => 2, 'memory_mib' => 2048, 'disk_gib' => 20]),
                ],
            ));

            RunProvisioningJob::dispatch((string) $job->getKey());

            return [$service, $job];
        });
    }

    /**
     * An archive at the controlled datastore with a verification in flight
     * against it, and the platform row that is waiting on that verification.
     *
     * The marker goes in the archive id because that is what the controlled
     * datastore reads a verification verdict from — it is the only thing the
     * call carries that a caller chooses.
     *
     * @return array{0: Backup, 1: ComputeCluster}
     */
    private function committedVerification(string $marker = ''): array
    {
        return $this->outsideTheTransaction(function () use ($marker): array {
            $customer = $this->committedCustomer();
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);
            $node = ComputeNode::factory()->withCapacity(32, 65_536, 2_000)->create([
                'cluster_id' => $cluster->getKey(),
            ]);

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Active,
            ]);

            $datastore = 'pbs-test-01';

            config()->set('backups.datastores.'.$cluster->slug, $datastore);

            $archive = 'vzdump-qemu-9001-'.($marker === '' ? 'clean' : $marker).'.vma.zst';

            /*
             * Started here and polled in another process, which is the point:
             * the task only exists for `backups:reconcile` because the
             * controlled datastore keeps what it was told outside this
             * process's memory.
             */
            $operation = app(BackupProviderFactory::class)
                ->for($cluster)
                ->startVerification($node->provider_name, $datastore, $archive);

            $backup = Backup::factory()->create([
                'customer_id' => $customer->getKey(),
                'service_id' => $service->getKey(),
                'cluster_id' => $cluster->getKey(),
                'node_name' => $node->provider_name,
                'datastore' => $datastore,
                'state' => BackupState::Verifying,
                'archive_id' => $archive,
                'provider' => 'fake',
                'provider_task_id' => $operation->taskId,
                'started_at' => now(),
                'verified' => null,
                'verified_at' => null,
                'failure_reason' => null,
            ]);

            return [$backup, $cluster];
        });
    }

    /**
     * Addresses this job is still holding.
     */
    private function liveReservationCount(ProvisioningJob $job): int
    {
        return IpReservation::query()
            ->where('provisioning_job_id', $job->getKey())
            ->whereNull('released_at')
            ->count();
    }
}
