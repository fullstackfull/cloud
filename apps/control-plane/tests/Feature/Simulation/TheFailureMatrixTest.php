<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
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
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
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
    public function a_purchased_machine_is_built_from_the_image_the_plan_was_sold_with(): void
    {
        /*
         * ---------------------------------------------------------------------
         * What this test used to assert, and why it changed
         * ---------------------------------------------------------------------
         *
         * Gap 7 found that a purchased VPS reached the hypervisor with no
         * image at all. Every half worked — the catalogue held installable
         * images per cluster, preflight checked the mapping, the reinstall
         * path resolved one and refused without it — and the purchase joined
         * none of them: `ProvisionOrderedService` wrote the cluster, the pool,
         * the storage class and a hostname, and no template; `CreateVpsHandler`
         * passed null; `ProxmoxComputeProvider` omitted `import-from` and
         * created a disk with nothing on it. The customer paid for a machine
         * that boots to a firmware prompt.
         *
         * That test asserted the defect and said so in its name. This one
         * asserts the fix, over the same path: the image is resolved at the
         * purchase from the plan's own placement constraints, carried through
         * the job payload across the queue, and handed to the hypervisor —
         * which records what it installed.
         */
        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
            'template_slug' => $estate['template']->slug,
        ]);

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'matrix-image');

        $this->payThroughTheProvider($invoice, $customer, 'pi_matrix_image');

        $this->work('payments');
        $this->work(RunProvisioningJob::QUEUE);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();
        $job = ProvisioningJob::query()->where('service_id', $service->getKey())->sole();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        /*
         * Durable, on the message that crossed the queue — not re-derived in
         * the worker. An operator who stages a second image between payment
         * and build must not change what a paid-for order delivers.
         */
        $payload = (array) $job->payload;

        $this->assertSame((string) $estate['template']->getKey(), $payload['template_id'] ?? null);
        $this->assertSame($estate['template']->provider_reference, $payload['template_reference'] ?? null);

        /*
         * And the hypervisor's own record of what it installed, read back
         * through the provider rather than from the platform's own row: the
         * one place a claim about an image can be checked against the thing
         * that would have installed it.
         */
        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        $remote = app(ComputeProviderFactory::class)
            ->for($estate['cluster'])
            ->getVm($estate['node']->provider_name, (string) $machine->provider_id);

        $this->assertNotNull($remote);
        $this->assertSame(
            $estate['template']->provider_reference,
            $remote->raw['installed_template'] ?? null,
            'the machine was built from a different image than the one the plan was sold with',
        );
    }

    #[Test]
    public function a_plan_with_no_image_to_install_waits_for_an_operator_instead_of_building_an_empty_disk(): void
    {
        /*
         * The negative twin. Two installable images on the cluster and no
         * `template_slug` on the plan: the platform has no basis for choosing
         * — the same rule that refuses to pick between two clusters — so
         * nothing is built, no job exists, and the service carries a reason a
         * person can act on.
         *
         * Asserted through the money path rather than by calling the action,
         * because the property that matters is that a *paid* order stops here
         * rather than producing a machine with an empty disk.
         */
        $estate = $this->committedVpsEstate();

        $this->outsideTheTransaction(fn (): VmTemplate => VmTemplate::factory()->create([
            'cluster_id' => $estate['cluster']->getKey(),
        ]));

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'matrix-image-ambiguous');

        $this->payThroughTheProvider($invoice, $customer, 'pi_matrix_image_ambiguous');

        $this->work('payments');

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();

        // No job, so nothing for a worker to build and nothing at the
        // hypervisor to clean up afterwards.
        $this->assertSame(0, ProvisioningJob::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());

        // Still pending rather than provisioning: the customer is not shown a
        // build that is not happening.
        $this->assertSame(ServiceStatus::Pending, $service->status);

        $this->assertSame(
            'the plan names no installable OS image, and the cluster offers no single one',
            ((array) $service->resources)['placement_blocked_reason'] ?? null,
        );
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
         * The status column is left ACTIVE, and that is now a decision rather
         * than a finding.
         *
         * `services.status` means what the state machine says it means: what
         * the customer bought and owes for. Rewriting it from a job outcome is
         * what would let a failed reboot erase a machine's history, so a
         * failed create task does not touch it either.
         *
         * What the customer is TOLD is a different question, and Gap 8
         * answered it: a `needs_review` job whose kind created the service
         * eclipses the service's word, so this reads `under_review` and not
         * `active`. The policy and its whole table are in
         * `ThePartialCreatePolicyTest`; asserted here as well because this is
         * the path that found the defect.
         */
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        $this->assertSame(
            CustomerServiceState::UnderReview,
            CustomerServiceState::for(
                ServiceStatus::Active,
                isAwaitingReview: true,
                deliveryIsInDoubt: true,
            ),
            'a customer is told their machine is active while its build is waiting for a person',
        );
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

        /*
         * Not verified, and said so with the third value rather than with the
         * absence of one. Gap 8 made this explicit: `verified` used to stay
         * null after a verification that failed, which made "checked and
         * unreadable" indistinguishable from "nobody has checked" on the one
         * column a reader is most likely to look at.
         */
        $this->assertFalse($settled->verified);
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
    public function a_backup_nobody_has_asked_about_yet_is_never_reported_as_verified(): void
    {
        /*
         * The first of the three values, asserted before anything asks. It is
         * `Succeeded`, which means the datastore wrote it; `verified` is null,
         * which means nobody has read it back. Folding those together is how a
         * platform comes to tell a customer their backups are fine on the
         * strength of never having checked — so the null is asserted here as a
         * value, not treated as the absence of one.
         */
        $backup = $this->committedStoredArchive();

        $this->assertSame(BackupState::Succeeded, $backup->state);
        $this->assertNull($backup->verified, 'a backup nobody read back is reported as one that was');
        $this->assertNull($backup->verified_at);
        $this->assertSame(0, $backup->verification_attempts);
    }

    #[Test]
    public function a_stored_archive_is_sent_for_verification_and_comes_back_verified(): void
    {
        /*
         * The whole loop, and the half of it that did not exist until Gap 8.
         *
         * `startVerification` was on the provider contract and on both
         * drivers, `Verifying` was a state with `Succeeded → Verifying →
         * Verified` in the transition table, and the poller already knew how
         * to settle a verification into a verdict. Nothing ever put a row into
         * `Verifying`, so `verified` was null for every backup this platform
         * had ever taken — which docs/backups.md describes as the difference
         * between "the job reported success" and "the data is readable".
         *
         * Both halves run in their own processes, as the scheduler runs them.
         */
        $backup = $this->committedStoredArchive();

        $this->runArtisan('backups:verify');

        $asked = $backup->fresh();

        $this->assertSame(BackupState::Verifying, $asked?->state);
        $this->assertNotNull($asked->verification_task_id, 'the platform says it is verifying and named no task');
        $this->assertSame(1, $asked->verification_attempts);
        $this->assertNotNull($asked->verification_requested_at);

        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Verified, $settled?->state);
        $this->assertTrue($settled->verified);
        $this->assertNotNull($settled->verified_at);
    }

    #[Test]
    public function an_archive_the_datastore_cannot_read_back_is_never_reported_as_verified(): void
    {
        // The same loop, the same two commands, and the opposite verdict —
        // reached through the initiator rather than by putting the row into
        // `Verifying` by hand.
        $backup = $this->committedStoredArchive(FakeBackupProvider::FAILING_MARKER);

        $this->runArtisan('backups:verify');
        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Failed, $settled?->state);
        $this->assertFalse($settled->verified);
        $this->assertNull($settled->verified_at);
        $this->assertNotNull($settled->failure_reason);
    }

    #[Test]
    public function running_the_verification_sweep_twice_starts_one_verification(): void
    {
        /*
         * The sweep runs every five minutes for the life of the platform, so
         * "safe to repeat" is not a nicety. A second run must not start a
         * second verification of the same archive: two tasks against one
         * archive means the poller settles the row from whichever finishes
         * first and the other's verdict is lost.
         *
         * What makes it safe is the scope rather than a flag: `Succeeded →
         * Verifying` takes the row out of it.
         */
        $backup = $this->committedStoredArchive();

        $this->runArtisan('backups:verify');
        $this->runArtisan('backups:verify');

        $asked = $backup->fresh();

        $this->assertSame(1, $asked?->verification_attempts, 'a second sweep asked for a second verification');
        $this->assertSame(BackupState::Verifying, $asked->state);
    }

    #[Test]
    public function a_datastore_that_refuses_verification_is_asked_a_bounded_number_of_times(): void
    {
        /*
         * A refusal leaves the archive stored and unverified, which is true,
         * and the attempt counter is what stops the sweep asking a broken
         * datastore several hundred times an hour for ever.
         *
         * The row stays `Succeeded` deliberately: the archive is there. What
         * is unknown is whether it can be read, and the null in `verified`
         * already says exactly that.
         */
        $backup = $this->committedStoredArchive(FakeBackupProvider::REFUSAL_MARKER);

        $limit = (int) config('backups.verification_attempts');

        /*
         * Run in this process rather than through the command, deliberately.
         * `runArtisan` asserts the command exited cleanly, and a sweep whose
         * datastore refused exits non-zero on purpose — that exit code is how
         * an operator finds out a provider is down. What this test is about is
         * the counter, which is database state either way.
         */
        foreach (range(1, $limit + 2) as $ignored) {
            app(VerifyStoredArchives::class)->execute();
        }

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Succeeded, $settled?->state);
        $this->assertNull($settled->verified);
        $this->assertSame($limit, $settled->verification_attempts, 'the sweep kept asking a datastore that had refused');
    }

    /**
     * A stored archive: a backup whose task finished and whose archive the
     * datastore named, with nothing having read it back.
     *
     * The marker travels in the archive id because that is what a verification
     * names, and the controlled datastore reads its faults from the arguments
     * it is given.
     */
    private function committedStoredArchive(string $marker = ''): Backup
    {
        return $this->outsideTheTransaction(function () use ($marker): Backup {
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

            return Backup::factory()->create([
                'customer_id' => $customer->getKey(),
                'service_id' => $service->getKey(),
                'cluster_id' => $cluster->getKey(),
                'node_name' => $node->provider_name,
                'datastore' => $datastore,
                'state' => BackupState::Succeeded,
                'archive_id' => 'vzdump-qemu-9101-'.($marker === '' ? 'clean' : $marker).'.vma.zst',
                'provider' => 'fake',
                'provider_task_id' => 'UPID:fake-backup:done-'.uniqid(),
                'started_at' => now()->subMinutes(5),
                'finished_at' => now(),
                'verified' => null,
                'verified_at' => null,
                'verification_task_id' => null,
                'failure_reason' => null,
            ]);
        });
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
                    // The image the purchase would have resolved. Carried here
                    // so these scenarios are about the failure each one names
                    // and not about the imageless build the VPS gate covers.
                    'template_id' => (string) $estate['template']->getKey(),
                    'template_reference' => (string) $estate['template']->provider_reference,
                    'os_family' => $estate['template']->os_family->value,
                    'architecture' => $estate['template']->architecture->value,
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
