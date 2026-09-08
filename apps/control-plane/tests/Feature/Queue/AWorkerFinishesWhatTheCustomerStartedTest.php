<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Application\Actions\RequestVpsReinstall;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use PHPUnit\Framework\Attributes\Test;

/**
 * The chains that only exist when somebody else finishes the work.
 *
 * `ARealWorkerConsumesTheQueueTest` proved one job survives a real Redis and a
 * real worker. This file proves the four sequences the customer actually
 * depends on, each of which crosses a process boundary at least once and none
 * of which had ever been executed by a worker:
 *
 *  - a build finishing and the customer being **told**, by a second worker on
 *    a different queue;
 *  - a **reinstall** travelling from the button the customer pressed to the
 *    hypervisor and back into the database;
 *  - a **payment** unlocking a machine that a suspension had locked, with the
 *    service reaching `active` only after the provider confirmed;
 *  - a **redelivered destructive job** whose first worker died, which must
 *    not rebuild the machine a second time.
 *
 * The hypervisor is shared between the processes through the fake provider's
 * state file, so the machine the worker operates on is the machine this test
 * created — not a coincidence of two fakes agreeing.
 */
final class AWorkerFinishesWhatTheCustomerStartedTest extends WorkerHarness
{
    private const string PROVISIONING = 'provisioning';

    private const string NOTIFICATIONS = 'notifications';

    private const string VM_ID = '910';

    #[Test]
    public function a_finished_build_reaches_the_customer_through_a_second_worker(): void
    {
        $job = $this->committedOrder();

        RunProvisioningJob::dispatch((string) $job->getKey());

        $this->work(self::PROVISIONING);

        $finished = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        $this->assertSame(ProvisioningJobStatus::Succeeded, $finished->status);

        /*
         * The build worker did not tell the customer anything: the listener is
         * queued, and it is queued onto a different queue on purpose so that a
         * fleet-wide reconciliation cannot delay a "your server is ready".
         * Nothing has been raised yet, and a message is waiting.
         */
        $this->assertSame(
            0,
            Notification::on(self::CONNECTION)->where('customer_id', $finished->customer_id)->count(),
            'The provisioning worker wrote the customer a notification itself, on the wrong queue.',
        );

        $this->assertGreaterThan(0, $this->queued(self::NOTIFICATIONS));

        $this->work(self::NOTIFICATIONS);

        $notification = Notification::on(self::CONNECTION)
            ->where('customer_id', $finished->customer_id)
            ->first();

        $this->assertNotNull($notification, 'The build finished and nobody told the customer.');
        $this->assertSame(NotificationType::ServiceReady, $notification->type);

        $deliveries = NotificationDelivery::on(self::CONNECTION)
            ->where('notification_id', $notification->getKey())
            ->get();

        $this->assertNotEmpty($deliveries, 'The notification was raised and never handed to a channel.');

        // Every delivery settled. A row still `pending` after the queue drained
        // is a message waiting for a worker that is not coming.
        foreach ($deliveries as $delivery) {
            $this->assertTrue(
                $delivery->status->isTerminal(),
                sprintf('The %s delivery is still %s.', $delivery->channel->value, $delivery->status->value),
            );
        }

        $inApp = $deliveries->firstWhere('channel', NotificationChannel::InApp);

        $this->assertNotNull($inApp);
        $this->assertSame(DeliveryStatus::Sent, $inApp->status);

        $this->assertSame(0, $this->queued(self::NOTIFICATIONS));
    }

    #[Test]
    public function a_reinstall_travels_from_the_customers_confirmation_to_the_hypervisor(): void
    {
        $machine = $this->committedMachine();

        $rocky = $this->outsideTheTransaction(fn (): VmTemplate => VmTemplate::factory()->create([
            'cluster_id' => $machine->cluster_id,
            'provider_reference' => 'local:import/rocky-10.qcow2',
            'os_family' => OsFamily::Rocky,
        ]));

        $job = $this->outsideTheTransaction(fn (): ProvisioningJob => app(RequestVpsReinstall::class)->execute(
            machine: $machine,
            confirmation: $machine->hostname,
            idempotencyKey: 'chain-reinstall-1',
            template: $rocky,
        ));

        /*
         * The customer's request is finished and their disk is untouched: the
         * operation exists, the message is on Redis, and the machine at the
         * hypervisor still has the image it booted with.
         */
        $this->assertSame(1, $this->queued(self::PROVISIONING));
        $this->assertSame(
            ReinstallState::Queued,
            VmReinstall::on(self::CONNECTION)->where('provisioning_job_id', $job->getKey())->sole()->state,
        );
        $this->assertSame('local:import/debian-13.qcow2', $this->remote()?->raw['installed_template'] ?? null);

        $this->work(self::PROVISIONING);

        $operation = VmReinstall::on(self::CONNECTION)->where('provisioning_job_id', $job->getKey())->sole();

        $this->assertSame(
            ReinstallState::Completed,
            $operation->state,
            'A worker in another process took the reinstall and did not finish it.',
        );

        // The disk really was replaced, at a hypervisor this process never
        // asked to do anything.
        $this->assertSame('local:import/rocky-10.qcow2', $this->remote()?->raw['installed_template'] ?? null);

        // And it is the same machine: same provider id, same node, same shape.
        $this->assertSame(self::VM_ID, $this->remote()?->providerId);
        $this->assertSame((string) $machine->provider_id, $operation->provider_resource_id);

        $rebuilt = VirtualMachine::on(self::CONNECTION)->findOrFail($machine->getKey());

        $this->assertSame((string) $rocky->getKey(), (string) $rebuilt->template_id);
        $this->assertSame($machine->hostname, $rebuilt->hostname);
        $this->assertSame($machine->disk_gib, $rebuilt->disk_gib);
        $this->assertSame(
            1,
            IpAssignment::on(self::CONNECTION)
                ->where('assignable_id', $machine->getKey())
                ->whereNull('released_at')
                ->count(),
            'The rebuild gave the customer a second address, or took their first one away.',
        );
    }

    #[Test]
    public function a_payment_unlocks_the_machine_before_the_service_is_called_active(): void
    {
        $machine = $this->committedMachine(ServiceStatus::Suspended);

        $service = Service::on(self::CONNECTION)->findOrFail($machine->service_id);

        $subscription = $this->outsideTheTransaction(fn (): Subscription => Subscription::factory()->create([
            'customer_id' => $service->customer_id,
            'status' => SubscriptionStatus::Suspended,
        ]));

        $service->forceFill(['subscription_id' => $subscription->getKey()])->save();

        // The machine as a suspension left it: powered off, locked at the
        // hypervisor, and not coming back on its own at the next node reboot.
        $this->provider()->suspendVm(
            (string) ComputeNode::on(self::CONNECTION)->findOrFail($machine->node_id)->provider_name,
            self::VM_ID,
            SuspensionPolicy::PowerOffAndLock,
        );

        $this->assertTrue($this->remote()?->isSuspendedByPlatform());

        $this->outsideTheTransaction(fn (): Subscription => app(TransitionSubscription::class)
            ->execute($subscription, SubscriptionStatus::Active));

        // The money has moved and nothing has been restored: the work is on
        // the queue and the machine is still locked.
        $this->assertSame(1, $this->queued(self::PROVISIONING));
        $this->assertTrue($this->remote()?->isSuspendedByPlatform());
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);

        $this->work(self::PROVISIONING);

        $state = $this->remote();

        $this->assertNotNull($state);
        $this->assertFalse($state->isSuspendedByPlatform(), 'The customer paid and the machine stayed locked.');
        $this->assertTrue($state->startsOnBoot);

        $this->assertSame(
            ServiceStatus::Active,
            $service->fresh()?->status,
            'The service did not come back, or came back without the provider confirming.',
        );
    }

    #[Test]
    public function a_reinstall_whose_worker_died_is_not_started_again_by_the_next_one(): void
    {
        /*
         * The crash is staged rather than raced: the window between the row
         * moving to `reinstalling` and the provider answering is microseconds
         * against a fake, and a test that had to hit it would be a test that
         * usually hit something else. What is real here is everything after —
         * a genuine message on Redis, a genuine worker in its own process, and
         * a genuine hypervisor that would happily rebuild the machine again if
         * it were asked.
         *
         * The evidence a dead worker leaves is the row: `reinstalling`, with
         * the moment the disk stopped being trustworthy stamped on it.
         */
        $machine = $this->committedMachine();

        $job = $this->outsideTheTransaction(fn (): ProvisioningJob => app(RequestVpsReinstall::class)->execute(
            machine: $machine,
            confirmation: $machine->hostname,
            idempotencyKey: 'chain-reinstall-crash',
        ));

        $operation = VmReinstall::on(self::CONNECTION)->where('provisioning_job_id', $job->getKey())->sole();

        $operation->forceFill([
            'state' => ReinstallState::Reinstalling,
            'state_changed_at' => now(),
            'destroyed_at' => now(),
        ])->save();

        $before = $this->remote()?->raw['installed_template'] ?? null;

        $this->work(self::PROVISIONING);

        $this->assertSame(
            ReinstallState::Indeterminate,
            $operation->fresh()?->state,
            'A worker picked up a half-finished destructive operation and treated it as fresh work.',
        );

        // The hypervisor was not touched. This is the assertion the whole test
        // exists for: a redelivered message must not cost a customer the data
        // the first attempt was in the middle of replacing.
        $this->assertSame($before, $this->remote()?->raw['installed_template'] ?? null);

        $settled = ProvisioningJob::on(self::CONNECTION)->findOrFail($job->getKey());

        $this->assertSame(
            ProvisioningJobStatus::NeedsReview,
            $settled->status,
            'An interrupted reinstall was left for a queue rather than for a person.',
        );

        // And it is not waiting to be tried again.
        $this->assertSame(0, $this->queued(self::PROVISIONING));
        $this->assertNull($settled->next_attempt_at);
    }

    /**
     * A customer with a service, a machine, an address and a hypervisor that
     * has heard of all of it — committed, so a worker can see it too.
     */
    private function committedMachine(ServiceStatus $status = ServiceStatus::Active): VirtualMachine
    {
        return $this->outsideTheTransaction(function () use ($status): VirtualMachine {
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);

            $node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
                'cluster_id' => $cluster->id,
                'status' => NodeStatus::Active,
            ]);

            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

            $service = Service::factory()->create([
                'customer_id' => $customer->id,
                'kind' => 'vps',
                'status' => $status,
                'label' => 'web-kw-01',
            ]);

            $template = VmTemplate::factory()->create([
                'cluster_id' => $cluster->id,
                'provider_reference' => 'local:import/debian-13.qcow2',
                'os_family' => OsFamily::Debian,
            ]);

            $machine = VirtualMachine::factory()
                ->onNode($node, (int) self::VM_ID)
                ->forService($service)
                ->create([
                    'hostname' => 'web-kw-01',
                    'storage_name' => 'local-lvm',
                    'template_id' => $template->getKey(),
                    'power_state' => PowerState::Running,
                    'disk_gib' => 40,
                ]);

            $subnet = Subnet::factory()->create(['prefix_length' => 24, 'gateway' => '192.0.2.1']);

            $address = IpAddress::factory()->create([
                'subnet_id' => $subnet->id,
                'address' => '192.0.2.40',
            ]);

            IpAssignment::factory()->create([
                'ip_address_id' => $address->id,
                'customer_id' => $customer->id,
                'service_id' => $service->getKey(),
                'assignable_type' => VirtualMachine::class,
                'assignable_id' => $machine->getKey(),
                'is_primary' => true,
                'assigned_at' => now(),
                'released_at' => null,
            ]);

            $this->provider($cluster)->createVirtualMachine(new CreateVmRequest(
                nodeName: $node->provider_name,
                vmId: (int) self::VM_ID,
                hostname: 'web-kw-01',
                vcpu: 2,
                memoryMib: 4096,
                diskGib: 40,
                storageName: 'local-lvm',
                startAfterCreate: true,
            ));

            // The image the machine booted with, which is what makes a later
            // "it was rebuilt" or "it was not touched" an observation rather
            // than an assumption.
            $this->provider($cluster)->reinstallVm(
                $node->provider_name,
                self::VM_ID,
                new ReinstallVmRequest(
                    templateReference: 'local:import/debian-13.qcow2',
                    storageName: 'local-lvm',
                    diskGib: 40,
                    hostname: 'web-kw-01',
                ),
            );

            return $machine;
        });
    }

    private function provider(?ComputeCluster $cluster = null): ComputeProvider
    {
        $cluster ??= ComputeCluster::on(self::CONNECTION)->firstOrFail();

        return app(ComputeProviderFactory::class)->for($cluster);
    }

    private function remote(): ?RemoteVmState
    {
        $machine = VirtualMachine::on(self::CONNECTION)->where('provider_id', self::VM_ID)->firstOrFail();

        $node = ComputeNode::on(self::CONNECTION)->findOrFail($machine->node_id);

        return $this->provider()->getVm((string) $node->provider_name, self::VM_ID);
    }
}
