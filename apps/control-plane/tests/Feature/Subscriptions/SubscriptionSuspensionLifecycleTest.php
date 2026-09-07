<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Compute\Application\Actions\EnforceComputeSuspension;
use Lynomia\Modules\Compute\Application\Actions\LiftComputeSuspension;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Actions\SuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\UnsuspendHostingAccount;
use Lynomia\Modules\Subscriptions\Application\Listeners\EnforceServiceStateForSubscription;
use Lynomia\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suspension and reactivation as the customer experiences them, from the
 * subscription edge down to the hypervisor.
 *
 * The unit tests next door prove the two actions do what they say to a
 * provider. This file proves the part that was missing entirely before this
 * phase: that a subscription going suspended actually reaches the hypervisor,
 * that paying reverses it, and — the asymmetric case the prompt singles out —
 * that a reactivation the provider will not confirm leaves the service
 * unusable and tells the customer, rather than marking it active because the
 * money arrived.
 */
final class SubscriptionSuspensionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ComputeProviderFactory $providers;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private Customer $customer;

    private Service $service;

    private string $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providers = app(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $this->node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->subscriptionId = (string) $subscription->getKey();

        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'subscription_id' => $this->subscriptionId,
            'label' => 'web-kw-01',
        ]);

        VirtualMachine::factory()->onNode($this->node, 400)->create([
            'service_id' => $this->service->getKey(),
            'power_state' => PowerState::Running,
        ]);

        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: 400,
            hostname: 'web-kw-01',
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            startAfterCreate: true,
        ));
    }

    #[Test]
    public function non_payment_suspends_the_service_and_the_machine(): void
    {
        $this->suspendSubscription();

        $this->assertSame(ServiceStatus::Suspended, $this->service->fresh()?->status);

        $state = $this->remote();

        $this->assertSame(PowerState::Stopped, $state?->powerState);
        $this->assertTrue($state->isSuspendedByPlatform());
        $this->assertFalse($state->startsOnBoot);
    }

    #[Test]
    public function an_operator_suspension_takes_the_same_path(): void
    {
        /*
         * An abuse suspension arrives at the same subscription edge as a
         * non-payment one. It is the same enforcement deliberately: a
         * suspension that depended on *why* it was imposed would be two
         * mechanisms, and the weaker one would be the one somebody found.
         */
        $this->suspendSubscription(from: SubscriptionStatus::Active);

        $this->assertSame(ServiceStatus::Suspended, $this->service->fresh()?->status);
        $this->assertTrue($this->remote()?->isSuspendedByPlatform());
    }

    #[Test]
    public function suspending_a_second_time_changes_nothing(): void
    {
        // A redelivered event, or a dunning sweep that runs twice.
        $this->suspendSubscription();
        $this->suspendSubscription();

        $this->assertSame(ServiceStatus::Suspended, $this->service->fresh()?->status);
        $this->assertTrue($this->remote()?->isSuspendedByPlatform());
    }

    #[Test]
    public function payment_reactivates_the_service_and_the_machine(): void
    {
        $this->suspendSubscription();

        $this->reactivateSubscription();

        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);

        $state = $this->remote();

        $this->assertFalse($state?->isLockedAtProvider());
        $this->assertSame(PowerState::Running, $state->powerState);
        $this->assertTrue($state->startsOnBoot);
    }

    #[Test]
    public function a_reactivation_the_provider_will_not_confirm_does_not_pretend(): void
    {
        /*
         * The case the phase brief names. The money has arrived and the
         * machine has not come back — a lock somebody else placed, a node that
         * is not answering — and the temptation is to mark the service active
         * because the billing half succeeded.
         *
         * The service must stay unusable and the customer must be told, or
         * they spend an afternoon debugging a server the platform has quietly
         * left switched off.
         */
        $this->suspendSubscription();

        $this->lockTakenBySomethingElse();

        $this->reactivateSubscription();

        $service = $this->service->fresh();

        $this->assertSame(ServiceStatus::Suspended, $service?->status);
        $this->assertFalse($service->isUsable());

        $notification = Notification::query()
            ->where('customer_id', $this->customer->id)
            ->where('type', NotificationType::ServiceReactivationFailed->value)
            ->first();

        $this->assertNotNull($notification, 'The customer was not told their reactivation failed.');
        $this->assertSame('/services', $notification->link);
    }

    #[Test]
    public function a_suspension_the_provider_will_not_confirm_still_suspends_the_service(): void
    {
        /*
         * The other asymmetry. Failing to reach the hypervisor must not leave
         * the customer's portal saying "active" — they would keep using a
         * service the platform has decided to cut off, and no later pass would
         * revisit it because the row would read active.
         *
         * The machine going missing at the provider is drift, which the
         * reconciler reports; the service is suspended either way.
         */
        $this->provider()->destroyVm($this->node->provider_name, '400');

        $this->suspendSubscription();

        $this->assertSame(ServiceStatus::Suspended, $this->service->fresh()?->status);
    }

    #[Test]
    public function a_reactivation_that_failed_is_retried_rather_than_skipped(): void
    {
        /*
         * A service left in `suspended` after a failed reactivation has to be
         * reachable by the next attempt. So does one left in `reactivating` by
         * a worker that died between the two calls — which is the shape a
         * crash takes here, since the row moves before the provider does.
         */
        $this->suspendSubscription();
        $this->lockTakenBySomethingElse();
        $this->reactivateSubscription();

        // The operator clears whatever was holding the machine.
        $this->releaseTheOtherLock();

        $this->reactivateSubscription();

        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);
    }

    #[Test]
    public function a_service_stranded_mid_reactivation_is_picked_up_again(): void
    {
        // Exactly where a worker crash leaves the row: the platform moved it
        // to reactivating and then stopped existing before the hypervisor
        // answered.
        $this->suspendSubscription();

        Service::query()->whereKey($this->service->getKey())
            ->update(['status' => ServiceStatus::Reactivating->value]);

        $this->reactivateSubscription();

        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);
        $this->assertFalse($this->remote()?->isLockedAtProvider());
    }

    #[Test]
    public function a_subscription_going_past_due_switches_nothing_off(): void
    {
        /*
         * Past due is a customer who is late, not one who has been cut off.
         * Acting on the destination rather than the edge would suspend
         * everybody the day after an invoice date.
         */
        $this->dispatch(SubscriptionStatus::Active, SubscriptionStatus::PastDue);

        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);
        $this->assertSame(PowerState::Running, $this->remote()?->powerState);
    }

    #[Test]
    public function recovering_from_past_due_does_not_touch_the_hypervisor(): void
    {
        // Nothing was switched off, so there is nothing to restore. An
        // unsuspend here would be a provider call per recovered subscription
        // in the fleet.
        $this->dispatch(SubscriptionStatus::PastDue, SubscriptionStatus::Active);

        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);
        $this->assertSame(PowerState::Running, $this->remote()?->powerState);
    }

    private function suspendSubscription(SubscriptionStatus $from = SubscriptionStatus::PastDue): void
    {
        $this->dispatch($from, SubscriptionStatus::Suspended);
    }

    private function reactivateSubscription(): void
    {
        $this->dispatch(SubscriptionStatus::Suspended, SubscriptionStatus::Active);
    }

    /**
     * Runs the listener directly, with one provider factory shared with the
     * assertions so both see the same fake hypervisor.
     */
    private function dispatch(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $listener = new EnforceServiceStateForSubscription(
            app(TransitionService::class),
            app(SuspendHostingAccount::class),
            app(UnsuspendHostingAccount::class),
            new EnforceComputeSuspension($this->providers),
            new LiftComputeSuspension($this->providers),
            app(NotifyCustomer::class),
        );

        $listener->handle(new SubscriptionStatusChanged(
            subscriptionId: $this->subscriptionId,
            customerId: (string) $this->customer->id,
            from: $from,
            to: $to,
            changedAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Something that is not the platform takes the machine's config lock — a
     * backup, a migration, an operator mid-repair.
     */
    private function lockTakenBySomethingElse(): void
    {
        $this->rewriteLock('backup');
    }

    private function releaseTheOtherLock(): void
    {
        $this->rewriteLock(SuspensionPolicy::LOCK_NAME);
    }

    private function rewriteLock(string $lock): void
    {
        $provider = $this->provider();

        $reflection = new \ReflectionProperty($provider, 'machines');
        $machines = $reflection->getValue($provider);

        $machines[$this->node->provider_name]['400'] = $machines[$this->node->provider_name]['400']
            ->withSuspension($lock, startsOnBoot: false);

        $reflection->setValue($provider, $machines);
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }

    private function remote(): ?RemoteVmState
    {
        return $this->provider()->getVm($this->node->provider_name, '400');
    }
}
