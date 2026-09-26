<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * I-1: a service an operator has ended is not billed for again.
 *
 * The re-audit after round two drove a live Shared Hosting account bought
 * through checkout through both operator doors and measured, verbatim:
 *
 *     PROBE hosting door: http=200 destroyed=yes
 *     PROBE after: account=terminated service=active order=active sub=active
 *     PROBE service door afterwards: http=202 code= service=terminated order=terminated sub=active
 *     PROBE renewal after both doors: ... considered=1 renewed=1 ...
 *
 * Two defects. The hosting-account door deleted the site and left the service
 * (and so the order, which follows it) reading `active`; and nothing that ends
 * a service reached the subscription, so the renewal sweep invoiced a site
 * that no longer existed. The VPS forced path has the same shape: the destroy
 * worker ends the service and nothing ends the subscription.
 *
 * Every case below ends with the renewal sweep run a period later, because
 * "the subscription reads cancelled" is only the means. The end is that no
 * invoice is issued.
 */
final class EndingAServiceEndsWhatItIsBilledForTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'hosting.retention.suspended_days' => 30,
            'provisioning.termination.suspended_retention_days' => 30,
        ]);
    }

    #[Test]
    public function the_hosting_account_door_ends_the_service_the_order_and_the_billing(): void
    {
        [$account, $service, $order, $subscription] = $this->liveHosting();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), [
                'reason' => 'Ticket 7731: erasure requested today.',
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HostingAccountStatus::Terminated->value);

        $this->assertFalse($this->sharedHostingPanelHas($account->username));
        $this->assertSame(HostingAccountStatus::Terminated, $account->fresh()?->status);

        // The site is gone, so nothing may say the purchase is live.
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);
        $this->assertSame(OrderStatus::Terminated, $order->fresh()?->status);

        $this->assertBillingHasEnded($subscription);
    }

    #[Test]
    public function the_service_door_ends_the_billing_for_a_live_hosting_service(): void
    {
        [$account, $service, $order, $subscription] = $this->liveHosting();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), [
                'reason' => 'Ticket 7731: erasure requested today.',
                'force' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', ServiceStatus::Terminated->value);

        $this->assertFalse($this->sharedHostingPanelHas($account->username));
        $this->assertSame(OrderStatus::Terminated, $order->fresh()?->status);

        $this->assertBillingHasEnded($subscription);
    }

    #[Test]
    public function a_suspended_hosting_service_ended_past_its_window_terminates_its_subscription(): void
    {
        /*
         * The unforced path, the one the retention sweep also takes: dunning
         * suspended the subscription, the window ran out, the service ended.
         * SUSPENDED has its own terminal edge, and it is the one taken.
         */
        [, $service, , $subscription] = $this->liveHosting();

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);

        $this->travel(31)->days();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), ['reason' => 'Unpaid since March, ticket 8891.'])
            ->assertStatus(202);

        $this->assertSame(SubscriptionStatus::Terminated, $subscription->refresh()->status);
        $this->assertBillingHasEnded($subscription);
    }

    #[Test]
    public function a_forced_end_of_an_active_vps_ends_its_billing_when_the_machine_is_gone(): void
    {
        /*
         * The unnumbered [X] observation: `DELETE /api/admin/services/{id}`
         * with `force` skips the window and the "still in service" check for
         * a VPS, queues the destroy, and nothing touched the subscription.
         * Measured here rather than inferred: the destroy job is run as the
         * worker would run it.
         */
        Queue::fake([RunProvisioningJob::class]);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->startOfSecond())
            ->priced(9_000)
            ->create(['customer_id' => $customer->getKey()]);

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'kind' => 'vps',
        ]);

        VirtualMachine::factory()->onNode(ComputeNode::factory()->create())->forService($service)->create([
            'hostname' => 'web-kw-11',
        ]);

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), [
                'reason' => 'Abuse report 4410: take it down now.',
                'force' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        // Still running until the worker has destroyed it, and still paid
        // for until then.
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);

        $job = ProvisioningJob::query()->sole();
        $this->assertSame(ProvisioningJobKind::DestroyVps, $job->kind);

        app()->call([new RunProvisioningJob((string) $job->getKey()), 'handle']);

        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status, 'Precondition: the worker ended the service.');

        $this->assertBillingHasEnded($subscription);
    }

    #[Test]
    public function a_subscription_that_still_pays_for_something_live_is_left_alone(): void
    {
        /*
         * The negative control. Ending one service ends the billing only when
         * nothing else on that subscription is still there.
         */
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $subscription = Subscription::factory()->priced(9_000)->create(['customer_id' => $customer->getKey()]);

        $ending = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
        ]);

        Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
        ]);

        app(TransitionService::class)->execute($ending, ServiceStatus::Terminated);

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertTrue($subscription->auto_renew);
    }

    private function assertBillingHasEnded(Subscription $subscription): void
    {
        $subscription->refresh();

        $this->assertTrue(
            $subscription->status->isTerminal(),
            'The subscription for an ended service still reads '.$subscription->status->value.'.',
        );
        $this->assertFalse($subscription->auto_renew);
        $this->assertNull($subscription->next_invoice_at);

        $before = Invoice::query()->where('subscription_id', $subscription->getKey())->count();

        $this->travelTo(CarbonImmutable::now()->addMonths(2));
        app(RenewDueSubscriptions::class)->execute();

        $this->assertSame(
            $before,
            Invoice::query()->where('subscription_id', $subscription->getKey())->count(),
            'A renewal was invoiced for a service that has ended.',
        );
    }

    /**
     * A live account, bought through checkout and built at the fake panel.
     *
     * @return array{HostingAccount, Service, Order, Subscription}
     */
    private function liveHosting(): array
    {
        $order = $this->buySharedHosting(
            Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']),
            $this->sharedHostingPlan(),
        );

        $service = Service::query()->where('order_id', $order->getKey())->sole();
        $account = HostingAccount::query()->where('service_id', $service->getKey())->sole();
        $subscription = Subscription::query()->where('order_id', $order->getKey())->sole();

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame(OrderStatus::Active, $order->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($this->sharedHostingPanelHas($account->username));

        return [$account, $service, $order, $subscription];
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ServiceTerminate->value, Permission::HostingAccountManage->value]);

        return $user->fresh() ?? $user;
    }
}
