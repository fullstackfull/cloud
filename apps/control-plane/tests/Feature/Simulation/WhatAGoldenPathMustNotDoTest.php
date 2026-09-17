<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Application\Actions\DetectVirtualMachineDrift;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The properties a golden path has to keep while it is busy being right.
 *
 * A workflow that crosses eight modules, three processes and a provider is
 * exactly where a secret leaks, an outbound request escapes, or a retry turns
 * into a storm — because each of those is invisible from inside any one module.
 * So they are asserted here, over the paths the rest of this directory proves.
 *
 * Every value used as a canary is secret-*shaped* and secret to nothing: a
 * string with the shape of a panel password or a provider token, chosen so that
 * a leak is findable by searching for it. Nothing here needs, reads or invents
 * a real credential.
 */
#[Group('golden-path')]
final class WhatAGoldenPathMustNotDoTest extends GoldenPathHarness
{
    /** A panel password's shape, and secret to nothing. */
    private const string PANEL_CANARY = 'Pnl-canary-7f3a9c2e51';

    /** A WordPress administrator's password, same idea. */
    private const string ADMIN_CANARY = 'Wp-canary-b4d8e1f062';

    #[Test]
    public function no_secret_shaped_value_a_golden_path_carries_is_left_anywhere_it_can_be_read(): void
    {
        $node = $this->outsideTheTransaction(static fn (): HostingNode => HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]));

        $plan = $this->committedPlan(ProductKind::SharedHosting, monthlyMinor: 4_500);

        $this->outsideTheTransaction(fn (): HostingPackage => HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'panel_package_name' => 'lyn_canary',
        ]));

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'canary-hosting');

        /*
         * The password the panel will be given, put in by hand where the
         * product would put a generated one. A generated secret would also be
         * unfindable afterwards, which is the opposite of what this test needs.
         */
        $job = $this->outsideTheTransaction(
            fn (): ProvisioningJob => app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::CreateHostingAccount,
                idempotencyKey: 'canary:'.$invoice->getKey(),
                provider: 'fake',
                serviceId: null,
                customerId: (string) $customer->getKey(),
                payload: [
                    'hosting_package_id' => (string) HostingPackage::query()
                        ->where('plan_id', $plan->getKey())->sole()->getKey(),
                    'username' => 'canaryone',
                    'primary_domain' => 'canaryone.example.test',
                    'password' => self::PANEL_CANARY,
                    'admin_password' => self::ADMIN_CANARY,
                    'contact_email' => 'owner@canaryone.example.test',
                ],
            )),
        );

        RunProvisioningJob::dispatch((string) $job->getKey());

        // The message on the wire, before anything runs it. §90: a job carries
        // ids and references, never a resolved secret.
        $queued = (array) Redis::connection()->lrange('queues:'.RunProvisioningJob::QUEUE, 0, -1);

        foreach ($queued as $message) {
            $this->assertStringNotContainsString(self::PANEL_CANARY, (string) $message);
            $this->assertStringNotContainsString(self::ADMIN_CANARY, (string) $message);
        }

        $this->work(RunProvisioningJob::QUEUE);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);

        /*
         * And then everywhere a person or a machine could read afterwards. The
         * payload column is the interesting one: it is written through the
         * redactor and cast through `RedactedJsonCast`, so a secret put into a
         * request never reaches the row — which is what this asserts rather
         * than assumes.
         */
        foreach ($this->placesSecretsCouldSettle() as $where => $text) {
            $this->assertStringNotContainsString(self::PANEL_CANARY, $text, 'the panel password reached '.$where);
            $this->assertStringNotContainsString(self::ADMIN_CANARY, $text, 'the admin password reached '.$where);
        }

        // Nothing failed, so nothing is in the failed-job table — which is its
        // own assertion, because a failed job serialises the whole message.
        $this->assertSame(0, (int) DB::connection(self::CONNECTION)->table('failed_jobs')->count());
    }

    #[Test]
    public function a_provider_message_that_quotes_its_own_credential_is_redacted_before_it_is_stored(): void
    {
        /*
         * A real datastore client prints the request it sent when it fails,
         * headers and all. The controlled one does the same on purpose, so
         * that "the platform redacts what a provider says" is a thing a test
         * can establish rather than a thing a reviewer hopes.
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

            return app(\Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup::class)->execute(
                $machine,
                notes: 'backup-refused: a datastore that answers with its own token',
            );
        });

        $settled = $backup->fresh();

        // Refused outright, so the row carries the provider's words.
        $this->assertSame(BackupState::Failed, $settled?->state);
        $this->assertNotNull($settled->failure_reason);

        // Kept, because "no space left on device" and "permission denied" need
        // different human responses — and scrubbed, because the message quotes
        // the credential the request carried.
        $this->assertStringNotContainsString('fake-datastore-token', (string) $settled->failure_reason);
        $this->assertStringNotContainsString('Authorization: Bearer', (string) $settled->failure_reason);
    }

    #[Test]
    public function a_whole_golden_path_reaches_no_network_at_all(): void
    {
        /*
         * §105. A rehearsal that made one outbound request would be a
         * rehearsal against somebody's real infrastructure, and the point of
         * the controlled estate is that there is nothing out there to reach.
         *
         * `preventStrayRequests()` throws on any request through the HTTP
         * client the adapters use, which is how every real provider in this
         * platform talks. It cannot see a raw socket, so it is a guard rather
         * than a proof — and the thing it guards is the one that has ever
         * actually happened: an adapter resolved by configuration instead of a
         * controlled one.
         */
        Http::preventStrayRequests();

        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'no-network');

        $this->payThroughTheProvider($invoice, $customer, 'pi_no_network');

        $this->work('payments');
        $this->work(RunProvisioningJob::QUEUE);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();

        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        // Reached here, so nothing in this process made an outbound request.
        // The worker is another process and cannot be guarded from here; what
        // covers it is the same controlled drivers and the production guards
        // that refuse a real one.
        $this->assertSame(1, VirtualMachine::query()->where('service_id', $service->getKey())->count());
    }

    #[Test]
    public function a_transient_failure_is_retried_a_bounded_number_of_times_and_then_stops(): void
    {
        /*
         * §94. The failure here never clears — the hostname carries the marker
         * for ever — so a platform that retried on hope would retry for ever.
         * What it must do instead is give up and say so, in a bounded number
         * of attempts, and leave the job where a person will find it.
         */
        $max = (int) config('provisioning.retry.max_attempts', 3);

        $estate = $this->committedVpsEstate();

        [$service, $job] = $this->outsideTheTransaction(function () use ($estate): array {
            $customer = $this->committedCustomer();

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Provisioning,
            ]);

            $job = app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::CreateVps,
                idempotencyKey: 'bounded:'.$service->getKey(),
                provider: $estate['cluster']->driver->value,
                serviceId: (string) $service->getKey(),
                customerId: (string) $customer->getKey(),
                payload: [
                    'cluster_id' => (string) $estate['cluster']->getKey(),
                    'ip_pool_id' => (string) $estate['pool']->getKey(),
                    'storage_class' => 'nvme',
                    'hostname' => 'provider-fail-bounded',
                    'vcpu' => 2,
                    'memory_mib' => 2048,
                    'disk_gib' => 20,
                ],
            ));

            return [$service, $job];
        });

        /*
         * Re-dispatched by hand rather than waited for. The platform schedules
         * the next attempt with a delay measured in minutes — correct in
         * production, and not something a test should sleep through — so this
         * delivers the message as the scheduler would and counts what happens.
         */
        for ($i = 0; $i < $max + 3; $i++) {
            if ($job->fresh()?->status !== ProvisioningJobStatus::Queued) {
                break;
            }

            RunProvisioningJob::dispatch((string) $job->getKey());

            $this->work(RunProvisioningJob::QUEUE);
        }

        $final = $job->fresh();

        $this->assertSame(
            ProvisioningJobStatus::NeedsReview,
            $final?->status,
            'a failure that never clears has to end up in front of a person',
        );

        $this->assertLessThanOrEqual($max, $final->attemptRecords()->count());
        $this->assertSame(0, $this->queued(RunProvisioningJob::QUEUE), 'something is still on the queue');
        $this->assertSame(0, VirtualMachine::query()->where('service_id', $service->getKey())->count());
    }

    #[Test]
    public function reconciling_the_same_disagreement_twice_records_it_once(): void
    {
        /*
         * §95. A drift condition persists until somebody fixes it, and a
         * reconciler that recorded a new row on every pass would bury the one
         * that mattered under a thousand copies of itself — and page somebody
         * a thousand times.
         */
        $estate = $this->committedVpsEstate();

        [$service] = $this->outsideTheTransaction(function () use ($estate): array {
            $customer = $this->committedCustomer();

            $service = Service::factory()->create([
                'customer_id' => $customer->getKey(),
                'kind' => 'vps',
                'status' => ServiceStatus::Provisioning,
            ]);

            $job = app(CreateProvisioningJob::class)->execute(new ProvisioningJobRequest(
                kind: ProvisioningJobKind::CreateVps,
                idempotencyKey: 'storm:'.$service->getKey(),
                provider: $estate['cluster']->driver->value,
                serviceId: (string) $service->getKey(),
                customerId: (string) $customer->getKey(),
                payload: [
                    'cluster_id' => (string) $estate['cluster']->getKey(),
                    'ip_pool_id' => (string) $estate['pool']->getKey(),
                    'storage_class' => 'nvme',
                    'hostname' => 'storm-one',
                    'vcpu' => 2,
                    'memory_mib' => 2048,
                    'disk_gib' => 20,
                ],
            ));

            RunProvisioningJob::dispatch((string) $job->getKey());

            return [$service, $job];
        });

        $this->work(RunProvisioningJob::QUEUE);

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        (new FakeComputeProvider)->stopVm($estate['node']->provider_name, (string) $machine->provider_id);

        app(DetectVirtualMachineDrift::class)->execute($estate['cluster']);
        app(DetectVirtualMachineDrift::class)->execute($estate['cluster']);
        app(DetectVirtualMachineDrift::class)->execute($estate['cluster']);

        $drifts = ResourceDrift::query()
            ->where('resource_type', 'virtual_machine')
            ->where('provider_reference', (string) $machine->provider_id)
            ->where('status', 'open')
            ->count();

        $this->assertSame(1, $drifts, 'three passes over one disagreement produced more than one open drift');
    }

    /**
     * Everywhere a secret could have settled, as text.
     *
     * @return array<string, string>
     */
    private function placesSecretsCouldSettle(): array
    {
        $connection = DB::connection(self::CONNECTION);

        $tables = [
            'provisioning_jobs' => 'the provisioning job payload',
            'provisioning_attempts' => 'an attempt record',
            'hosting_accounts' => 'the hosting account row',
            'audit_entries' => 'the audit trail',
            'services' => 'the service row',
            'notifications' => 'a customer notification',
        ];

        $found = [];

        foreach ($tables as $table => $label) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $found[$label] = $connection->table($table)->get()->toJson();
        }

        return $found;
    }
}
