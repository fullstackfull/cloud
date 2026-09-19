<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Application\Jobs\SyncDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dns\Application\Jobs\PublishRecord;
use Lynomia\Modules\Dns\Application\Jobs\PublishZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Jobs\PublishReverseDnsRecord;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queue\WorkerHarness;

/**
 * The proof that a controlled provider is one provider and not one per process.
 *
 * ---------------------------------------------------------------------------
 * Why this is the first test in the gap
 * ---------------------------------------------------------------------------
 *
 * Because without it, five of the platform's eight provider families cannot
 * take part in a workflow at all — and a workflow is what this phase is about.
 *
 * A simulator that keeps its state in a private array is a perfectly good
 * answer to "does this adapter honour its contract". It is no answer at all to
 * "does a hosting account created by a worker exist as far as the request that
 * reads it is concerned", because the worker and the request are two processes
 * and the array is in one of them. Compute and the registrar had each grown a
 * state file of their own, which is precisely why every cross-process proof in
 * this repository was about a machine or a domain name.
 *
 * Each test below mutates a provider in one process and reads it in another.
 * Not two calls on one object: a `queue:work` or an `artisan` process with its
 * own memory, its own container and its own instance of the simulator, which
 * can only agree with this one through
 * {@see ControlledSimulationStore}.
 *
 * Every one of them fails if the store is taken away — breakage J proves that
 * rather than asserting it.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * Compute and the registrar, because they are already proved across a process
 * by `ARealWorkerConsumesTheQueue`, `AWorkerFinishesWhatTheCustomerStarted`,
 * `TheNewSweepsRunOutsideThisProcess` and
 * `ADomainPurchaseSurvivesARealWorker`. Those tests moved onto the shared store
 * with everything else and still pass, which is the migration's own proof.
 */
#[Group('golden-path')]
final class AControlledProviderRemembersAcrossProcessesTest extends WorkerHarness
{
    #[Test]
    public function a_hosting_account_a_worker_creates_is_one_this_process_can_see(): void
    {
        [$job, $username] = $this->outsideTheTransaction(function (): array {
            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
            $package = HostingPackage::factory()->named('lyn_starter')->create();

            HostingNode::factory()->create([
                'panel' => HostingPanel::Fake,
                'max_accounts' => 50,
                'account_count' => 0,
                'disk_total_mib' => 2_097_152,
                'disk_used_mib' => 209_715,
            ]);

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'shared_hosting',
                'status' => ServiceStatus::Provisioning,
            ]);

            $username = 'xproc'.substr((string) $service->getKey(), -5);

            $job = ProvisioningJob::factory()->create([
                'customer_id' => $customer->getKey(),
                'service_id' => $service->getKey(),
                'kind' => ProvisioningJobKind::CreateHostingAccount,
                'status' => ProvisioningJobStatus::Queued,
                'provider' => 'fake',
                'idempotency_key' => 'cross-process-hosting:'.$service->getKey(),
                'payload' => [
                    'hosting_package_id' => (string) $package->getKey(),
                    'username' => $username,
                    'primary_domain' => $username.'.example.test',
                    'password' => 'not-a-real-panel-password',
                    'contact_email' => 'owner@'.$username.'.example.test',
                ],
            ]);

            return [$job, $username];
        });

        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(
            ProvisioningJobStatus::Succeeded,
            ProvisioningJob::query()->whereKey($job->getKey())->sole()->status,
            'the worker did not finish the build, so nothing below is about the provider',
        );

        /*
         * The assertion that matters: this process asks the panel what it is
         * holding, and the panel names an account it has never been told about
         * by anything in this process.
         */
        $node = HostingNode::query()->where('panel', HostingPanel::Fake)->sole();

        $accounts = array_map(
            static fn (object $account): string => (string) $account->username,
            app(HostingProviderFactory::class)->for($node)->listAccounts($node),
        );

        $this->assertContains($username, $accounts);
    }

    #[Test]
    public function a_zone_and_a_record_a_worker_publishes_are_ones_this_process_can_see(): void
    {
        [$zone, $record] = $this->outsideTheTransaction(function (): array {
            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

            $zone = DnsZone::factory()->create([
                'customer_id' => $customer->getKey(),
                'name' => 'xproc-'.substr((string) $customer->getKey(), -6).'.test',
                'state' => DnsState::Pending,
                'provider' => 'fake',
            ]);

            $record = DnsRecord::factory()->create([
                'dns_zone_id' => $zone->getKey(),
                'name' => 'www.'.$zone->name,
                'state' => DnsState::Pending,
            ]);

            return [$zone, $record];
        });

        // The zone first: a record cannot be published into a zone the provider
        // does not hold, which is the platform's own ordering and not this
        // test's.
        PublishZone::dispatch((string) $zone->getKey());

        $this->work('default');

        $this->assertSame(DnsState::Active, $zone->fresh()?->state);

        PublishRecord::dispatch((string) $record->getKey());

        $this->work('default');

        $this->assertSame(DnsState::Active, $record->fresh()?->state);

        $provider = app(DnsProviderFactory::class)->make();

        $held = $provider->findZone($zone->name);

        $this->assertNotNull($held, 'the worker published a zone this process cannot see');

        $names = array_map(
            static fn (object $value): string => (string) $value->name(),
            $provider->records($held),
        );

        $this->assertContains('www.'.$zone->name, $names);
    }

    #[Test]
    public function a_ptr_a_worker_publishes_is_one_this_process_can_see(): void
    {
        $address = '198.51.100.'.random_int(20, 200);

        $record = $this->outsideTheTransaction(function () use ($address): ReverseDnsRecord {
            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
            $pool = IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);
            $subnet = Subnet::factory()->forBlock('198.51.100.0/24', gateway: '198.51.100.1')
                ->create(['ip_pool_id' => $pool->getKey()]);

            $ip = IpAddress::factory()->create([
                'subnet_id' => $subnet->getKey(),
                'address' => $address,
            ]);

            IpAssignment::factory()->create([
                'ip_address_id' => $ip->getKey(),
                'customer_id' => $customer->getKey(),
                'released_at' => null,
            ]);

            return ReverseDnsRecord::factory()->create([
                'ip_address_id' => $ip->getKey(),
                'hostname' => 'mail.xproc.example.test',
                'status' => ReverseDnsStatus::Pending,
            ]);
        });

        PublishReverseDnsRecord::dispatch((string) $record->getKey());

        $this->work('default');

        $this->assertSame(ReverseDnsStatus::Active, $record->fresh()?->status);

        $this->assertSame(
            'mail.xproc.example.test',
            app(ReverseDnsProviderFactory::class)->make()->publishedFor($address),
            'the worker published a PTR this process cannot see',
        );
    }

    #[Test]
    public function a_power_state_this_process_set_is_the_one_the_worker_reads(): void
    {
        [$server, $endpoint] = $this->outsideTheTransaction(function (): array {
            $server = DedicatedServer::factory()->status(DedicatedServerStatus::Active)->create([
                'power_state' => PowerState::Off,
            ]);

            $endpoint = BmcEndpoint::factory()->forServer($server)->create();

            return [$server, $endpoint];
        });

        /*
         * This process turns the machine on, through the provider the platform
         * itself would use. The row still says off: nothing has read the
         * controller back yet.
         */
        app(DedicatedProviderFactory::class)->for($endpoint)->powerOn($endpoint);

        $this->assertSame(PowerState::Off, $server->fresh()?->power_state);

        SyncDedicatedServer::dispatch((string) $server->getKey());

        $this->work(SyncDedicatedServer::QUEUE);

        /*
         * And the worker — a process that never saw the call above — read the
         * controller and found it on. Without a shared simulator it would have
         * found the default, which is off, and written that.
         */
        $this->assertSame(PowerState::On, $server->fresh()?->power_state);
    }

    #[Test]
    public function a_backup_this_process_asked_for_is_settled_by_two_other_processes(): void
    {
        [$backup, $machine] = $this->outsideTheTransaction(function (): array {
            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
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

            /*
             * The datastore this cluster backs up to. Set here and not in the
             * other process on purpose: the reconciler works from the
             * datastore recorded on the row, which is the platform's own
             * design — a row that moved datastore between the request and the
             * poll would otherwise be polled against the wrong one.
             */
            config()->set('backups.datastores.'.$cluster->slug, 'pbs-test-01');

            return [app(RequestServiceBackup::class)->execute($machine), $machine];
        });

        $this->assertNotNull($backup->provider_task_id, 'nothing was started at the datastore');
        $this->assertContains($backup->fresh()?->state, [BackupState::Requested, BackupState::Running]);

        /*
         * Two passes in two processes, deliberately. The simulator reports a
         * task as running once before it settles, so the first process has to
         * leave the poll count behind for the second to find — which makes this
         * a proof about shared state twice over: the task, and its progress.
         */
        $this->runArtisan('backups:reconcile');
        $this->runArtisan('backups:reconcile');

        $settled = $backup->fresh();

        $this->assertSame(BackupState::Succeeded, $settled?->state);
        $this->assertNotNull($settled->archive_id);

        /*
         * And the archive the third process — this one — asks the datastore
         * for.
         */
        $archives = array_map(
            static fn (object $remote): string => (string) $remote->archiveId,
            app(BackupProviderFactory::class)->for($settled->cluster()->sole())->listBackups(
                $settled->node_name,
                $settled->datastore,
                (string) $machine->provider_id,
            ),
        );

        $this->assertContains((string) $settled->archive_id, $archives);
    }
}
