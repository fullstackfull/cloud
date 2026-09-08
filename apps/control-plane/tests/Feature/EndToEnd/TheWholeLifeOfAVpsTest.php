<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One machine, from the order that paid for it to the disk being wiped.
 *
 * Every other test in this suite proves one step. This proves the sequence,
 * which is a different claim: each step here starts from whatever the previous
 * one actually left behind, so a step that quietly depends on a fixture no
 * earlier step produces fails here and passes everywhere else.
 *
 * ---------------------------------------------------------------------------
 * One hypervisor for the whole life
 * ---------------------------------------------------------------------------
 *
 * The compute factory is deliberately not a singleton — an adapter belongs to
 * a cluster, not to a deployment — so every HTTP request in this test builds
 * its own fake hypervisor, and without help each would start with an empty
 * fleet. The fake's state file is what makes them one machine: the same
 * fixture the queue suite uses to let a worker in another process operate on
 * the machine this one created.
 *
 * The provider boundary is the fake. Everything above it — the money, the
 * subscription, the service state machine, the job engine, the notifications,
 * IPAM, node capacity, the audit trail — is the real thing.
 */
final class TheWholeLifeOfAVpsTest extends TestCase
{
    use RefreshDatabase;

    private string $fleetPath = '';

    private Customer $customer;

    private User $user;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private ?Product $product = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The operator who ends the service at the end of this life needs a
        // role, and roles come from the seeder rather than from a factory.
        $this->seed(RolePermissionSeeder::class);

        $this->fleetPath = sys_get_temp_dir().'/lynomia-life-'.getmypid().'-'.uniqid().'.state';
        config()->set('compute.fake.state_path', $this->fleetPath);
        config()->set('billing.providers.backup', 'fake');

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active', 'driver' => 'fake']);
        $this->node = ComputeNode::factory()->withCapacity(64, 262_144, 4_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);

        ComputeStorage::factory()->available(4_000)->create([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->id,
        ]);

        config()->set('backups.datastores.'.$this->cluster->slug, 'pbs-test-01');

        VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_reference' => 'local:import/debian-13.qcow2',
            'os_family' => OsFamily::Debian,
        ]);

        $pool = IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);
        $network = Network::factory()->create(['bridge' => 'vmbr1', 'vlan_id' => 1234]);

        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create([
            'ip_pool_id' => $pool->id,
            'network_id' => $network->id,
        ]);

        app(SeedSubnetAddresses::class)->execute($subnet);

        /*
         * One backup provider for the whole test.
         *
         * The fake numbers its task ids from an instance counter, and the
         * factory is deliberately not a singleton — so a second backup taken
         * through a second instance reuses the first one's task id and
         * collides with the unique index on (provider, provider_task_id). The
         * collision is a fixture artefact, and binding the factory once is
         * what makes "this machine has two backups" expressible at all.
         */
        $this->app->singleton(BackupProviderFactory::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->fleetPath !== '' && is_file($this->fleetPath)) {
            @unlink($this->fleetPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_customer_buys_a_server_uses_it_and_gives_it_back(): void
    {
        // ---------------------------------------------------------------
        // Bought and built
        // ---------------------------------------------------------------
        $service = $this->buy($this->plan('small', vcpu: 2, memoryMib: 4096, diskGib: 40));

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        $this->assertTrue($machine->existsRemotely());
        $this->assertNotNull($this->remote($machine), 'The build succeeded and the hypervisor has no machine.');

        // The customer was told, by the listener the build's own event woke.
        $this->assertTrue(
            Notification::query()
                ->where('customer_id', $this->customer->getKey())
                ->where('type', NotificationType::ServiceReady)
                ->exists(),
            'The server was built and nobody told the customer.',
        );

        // And it has exactly one address, live.
        $this->assertSame(1, $this->liveAssignments($machine)->count());

        // ---------------------------------------------------------------
        // Power
        // ---------------------------------------------------------------
        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'life-stop-1')
            ->postJson('/api/v1/vps/'.$machine->getKey().'/power', ['action' => 'stop'])
            ->assertStatus(202);

        $this->assertSame(PowerState::Stopped, $this->remote($machine)?->powerState);

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'life-start-1')
            ->postJson('/api/v1/vps/'.$machine->getKey().'/power', ['action' => 'start'])
            ->assertStatus(202);

        $this->assertSame(PowerState::Running, $this->remote($machine)?->powerState);

        // ---------------------------------------------------------------
        // Plan change, and the resize that makes it true
        // ---------------------------------------------------------------
        $bigger = $this->plan('large', vcpu: 4, memoryMib: 8192, diskGib: 80);

        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'life-plan-change-1')
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', [
                'plan_id' => (string) $bigger->getKey(),
                'price_id' => (string) $bigger->prices()->firstOrFail()->getKey(),
            ])
            ->assertOk();

        // The money moved and the machine caught up: both halves, because
        // either one alone is a customer with a complaint.
        $this->assertSame((string) $bigger->getKey(), (string) $subscription->refresh()->plan_id);

        $resized = $this->remote($machine);

        $this->assertSame(4, $resized?->vcpu);
        $this->assertSame(8192, $resized->memoryMib);
        $this->assertSame(80, $resized->diskGib);

        // ---------------------------------------------------------------
        // Suspension, at the hypervisor and not only in the database
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);

        $suspended = $this->remote($machine);

        $this->assertTrue($suspended?->isSuspendedByPlatform());
        $this->assertSame(PowerState::Stopped, $suspended->powerState);
        $this->assertFalse($suspended->startsOnBoot);

        // Nothing the customer can do reaches the machine while it is off.
        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'life-start-while-suspended')
            ->postJson('/api/v1/vps/'.$machine->getKey().'/power', ['action' => 'start'])
            ->assertStatus(409);

        // ---------------------------------------------------------------
        // They pay, and only then does it come back
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Active);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertFalse($this->remote($machine)?->isSuspendedByPlatform());

        // ---------------------------------------------------------------
        // Backup, and a restore from it
        // ---------------------------------------------------------------
        $backup = app(RequestServiceBackup::class)->execute($machine->refresh());

        $this->assertContains($backup->state, [BackupState::Requested, BackupState::Running, BackupState::Succeeded]);

        // Finished, as the reconciler would have found it. The archive's own
        // progress is the backup suite's subject; what this one needs is a
        // backup a customer could actually restore from.
        $backup->forceFill([
            'state' => BackupState::Succeeded,
            'finished_at' => now(),
            'size_bytes' => 1024,
            'archive_id' => 'vm/'.$machine->provider_id.'/2026-09-08T00:00:00Z',
        ])->save();

        $restored = app(RestoreServiceBackup::class)->execute(
            $backup->refresh(),
            $machine->refresh(),
            $machine->hostname,
        );

        $this->assertContains($restored->state, [BackupState::Restoring, BackupState::Restored]);
        $this->assertNotNull($restored->restore_started_at);

        // ---------------------------------------------------------------
        // Rebuilt, in place
        // ---------------------------------------------------------------
        $rocky = VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_reference' => 'local:import/rocky-10.qcow2',
            'os_family' => OsFamily::Rocky,
        ]);

        $providerIdBefore = (string) $machine->provider_id;

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'life-reinstall-1')
            ->postJson('/api/v1/vps/'.$machine->getKey().'/reinstall', [
                'confirm_hostname' => $machine->hostname,
                'template_id' => (string) $rocky->getKey(),
            ])
            ->assertStatus(202);

        $operation = VmReinstall::query()->where('virtual_machine_id', $machine->getKey())->sole();

        $this->assertSame(ReinstallState::Completed, $operation->state);
        $this->assertSame('local:import/rocky-10.qcow2', $this->remote($machine)?->raw['installed_template'] ?? null);

        // The same machine: same id at the provider, same address, same row.
        $this->assertSame($providerIdBefore, (string) $machine->refresh()->provider_id);
        $this->assertSame(1, $this->liveAssignments($machine)->count());

        // ---------------------------------------------------------------
        // A console permit
        // ---------------------------------------------------------------
        $this->actingAs($this->user)
            ->getJson('/api/v1/vps/'.$machine->getKey().'/console')
            ->assertStatus(201)
            ->assertJsonPath('data.single_use', true);

        // The restore finished, as the poller would have found it. A row left
        // mid-restore is not one the retention hold covers, and the point of
        // the next step is what the hold does to a finished backup.
        $backup->refresh()->forceFill(['state' => BackupState::Restored])->save();

        // ---------------------------------------------------------------
        // A backup the customer removes themselves
        // ---------------------------------------------------------------
        $second = app(RequestServiceBackup::class)->execute($machine->refresh());

        $second->forceFill([
            'state' => BackupState::Succeeded,
            'finished_at' => now(),
            'size_bytes' => 2048,
            'archive_id' => 'vm/'.$machine->provider_id.'/second',
        ])->save();

        $this->actingAs($this->user)
            ->deleteJson(
                '/api/v1/vps/'.$machine->getKey().'/backups/'.$second->getKey(),
                ['confirm_backup_id' => (string) $second->getKey()],
            )
            ->assertOk()
            // Asked for, not gone: nothing has been said to the datastore yet,
            // and the row must not claim otherwise.
            ->assertJsonPath('data.is_being_deleted', true);

        // ---------------------------------------------------------------
        // Given back, by the customer rather than by an operator
        // ---------------------------------------------------------------
        $this->actingAs($this->user)
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/cancel', [
                'immediately' => true,
                'confirm_subscription_id' => (string) $subscription->getKey(),
            ])
            ->assertOk();

        $service->refresh();

        $this->assertSame(ServiceStatus::Suspended, $service->status);

        // The date the data goes, written down where the customer can see it.
        $this->assertNotNull($service->retention_ends_at);
        $this->assertSame(BeginRetentionWindow::BY_CUSTOMER, $service->ended_reason);

        // And the backups they did not delete are held past the ordinary
        // sweep, which is the entire purpose of the window.
        $this->assertNotNull($backup->refresh()->protected_until);

        // The retention window is a month, and it is the only thing standing
        // between a cancellation made in a hurry and a destroyed dataset — so
        // the test waits it out rather than reaching past it.
        $this->travel(31)->days();

        // Nobody presses anything. The scheduler's own sweep is what ends it.
        $this->artisan('services:end-expired')->assertSuccessful();

        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        $this->assertNull(VirtualMachine::query()->find($machine->getKey()));
        $this->assertNull($this->remote($machine), 'The service ended and the machine is still at the hypervisor.');

        // The address is out of service and specifically not back in the pool.
        $this->assertSame(0, $this->liveAssignments($machine)->count());
        $this->assertSame(
            IpAddressStatus::Quarantined,
            IpAddress::query()->findOrFail($this->anyAddressOf($machine))->status,
        );

        // And the node has its room back.
        $this->assertSame(0, $this->node->refresh()->allocated_cpu_cores);
    }

    /**
     * An order, paid, fulfilled and built — the state every later step starts
     * from.
     */
    private function buy(Plan $plan): Service
    {
        /** @var Order $order */
        $order = app(PlaceOrder::class)->execute(
            $this->customer,
            new CheckoutRequest(
                lines: [new CheckoutLine((string) $plan->getKey(), 1)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: null,
            ),
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);

        /** @var Service $service */
        $service = Service::query()->where('order_id', $order->getKey())->sole();

        return $service;
    }

    private function plan(string $slug, int $vcpu, int $memoryMib, int $diskGib, int $monthlyMinor = 9_000): Plan
    {
        // One product for the whole catalogue in this test: a plan change is
        // refused across products, deliberately — moving a VPS subscription
        // onto a hosting plan is not a change, it is a different purchase.
        $product = $this->product ??= Product::factory()->create(['kind' => 'vps']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => $slug.'-'.uniqid(),
            'resources' => [
                'vcpu' => $vcpu,
                'memory_mib' => $memoryMib,
                'disk_gib' => $diskGib,
                'ipv4_count' => 1,
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    /**
     * What the hypervisor says about this machine, asked freshly every time.
     *
     * A held provider instance would be a cache of what the test believes; the
     * state file is what makes a new one see what the last request did.
     */
    private function remote(VirtualMachine $machine): ?RemoteVmState
    {
        return app(ComputeProviderFactory::class)
            ->for($this->cluster)
            ->getVm($this->node->provider_name, (string) $machine->provider_id);
    }

    /**
     * @return Collection<int, IpAssignment>
     */
    private function liveAssignments(VirtualMachine $machine): Collection
    {
        return IpAssignment::query()
            ->where('assignable_id', $machine->getKey())
            ->whereNull('released_at')
            ->get();
    }

    private function anyAddressOf(VirtualMachine $machine): string
    {
        return (string) IpAssignment::query()
            ->where('assignable_id', $machine->getKey())
            ->orderBy('created_at')
            ->firstOrFail()
            ->ip_address_id;
    }
}
