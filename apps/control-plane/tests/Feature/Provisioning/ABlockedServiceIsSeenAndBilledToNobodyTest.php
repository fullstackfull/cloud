<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The other end of the same rule: what happens to a service the platform
 * could not place after the money has already moved.
 *
 * Checkout now refuses a plan whose placement this platform's own rows cannot
 * describe, so this should be rare. It is not impossible, and the race is the
 * honest reason: an operator can delete a hosting package, or stage a second
 * cluster, in the seconds between a payment and the build. Three things then
 * have to be true, and none of them was:
 *
 *  1. the service is kept and marked, not failed and not silently dropped —
 *     the customer has paid, and a row an operator can find is the only
 *     honest outcome;
 *  2. an operator can actually read that reason, which until now required a
 *     SQL client;
 *  3. the subscription behind it does not bill a second period for something
 *     that was never delivered.
 *
 * The reason itself names a cluster, an IP pool or a panel package. That is
 * operator language about internal topology, so it is published on the staff
 * surface and stripped from the customer's own copy of the same row.
 */
final class ABlockedServiceIsSeenAndBilledToNobodyTest extends ServiceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ---- the service survives the race -----------------------------------

    #[Test]
    public function a_paid_service_the_platform_can_no_longer_place_is_kept_and_marked(): void
    {
        [$customer] = $this->accountWithOwner();
        $plan = $this->hostingPlan(withPackage: true);

        // Paid for while the configuration existed.
        $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1, 'blocked.example.test')],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: null,
        ));

        /** @var OrderItem $item */
        $item = OrderItem::query()->where('order_id', $order->getKey())->sole();

        // And removed before the worker got to it.
        HostingPackage::query()->where('plan_id', $plan->getKey())->delete();

        $service = app(ProvisionOrderedService::class)->execute($order->fresh(), $item);

        $this->assertNotNull($service, 'The customer paid; a service row must exist for them to be found by.');
        $this->assertSame(ServiceStatus::Pending, $service->status);

        $reason = ((array) $service->fresh()?->resources)['placement_blocked_reason'] ?? null;
        $this->assertIsString($reason);
        $this->assertStringContainsString('hosting package', $reason);

        // Nothing was asked of a provider: a job with no placement fails at
        // the panel and reads as an outage rather than a decision nobody made.
        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    // ---- renewal ---------------------------------------------------------

    #[Test]
    public function a_blocked_never_delivered_service_is_not_billed_for_a_second_period(): void
    {
        /*
         * Two customers, one sweep. The blocked one must produce nothing and
         * the delivered one must renew exactly as it always did — which is
         * what makes this a test of the rule rather than of a sweep that
         * happened to do nothing at all.
         */
        [$blockedCustomer] = $this->accountWithOwner();
        [$deliveredCustomer] = $this->accountWithOwner();

        $blocked = $this->subscriptionFor($blockedCustomer);
        $delivered = $this->subscriptionFor($deliveredCustomer);

        $this->serviceFor($blockedCustomer, [
            'subscription_id' => $blocked->getKey(),
            'status' => ServiceStatus::Pending,
            'resources' => ['vcpu' => 2, 'placement_blocked_reason' => 'no single IP pool, and the plan names none'],
        ]);

        $this->serviceFor($deliveredCustomer, [
            'subscription_id' => $delivered->getKey(),
            'status' => ServiceStatus::Active,
            'activated_at' => now(),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));

        $sweep = app(RenewDueSubscriptions::class)->execute();

        // The positive control: the sweep really did consider both, and really
        // did renew one. A run that found nothing would prove nothing.
        $this->assertSame(2, $sweep->considered);
        $this->assertSame(1, $sweep->renewed);
        $this->assertSame(1, $sweep->skipped);
        $this->assertSame(0, $sweep->failed, 'A service nobody can place is not a renewal failure to alarm about.');

        // Exactly one invoice exists, and it is the delivered customer's.
        $invoice = Invoice::query()->sole();
        $this->assertSame($delivered->getKey(), $invoice->subscription_id);
        $this->assertSame($deliveredCustomer->getKey(), $invoice->customer_id);

        // The blocked subscription's clock did not move either: a period it
        // advanced past is a period no later sweep would ever bill.
        $this->assertTrue(
            $blocked->refresh()->current_period_end->equalTo(CarbonImmutable::parse('2026-06-01 00:00:00')),
            'The blocked subscription advanced a period it was never invoiced for.',
        );

        $this->assertTrue(
            $delivered->refresh()->current_period_end->equalTo(CarbonImmutable::parse('2026-07-01 00:00:00')),
            'The delivered service must renew exactly as it did before this rule existed.',
        );
    }

    #[Test]
    public function a_pending_service_with_no_blocked_reason_still_renews(): void
    {
        /*
         * The rule is deliberately two conditions, not one. A service that is
         * merely PENDING is one the worker has not reached yet — a queue a few
         * seconds behind is not a delivery failure, and stopping its
         * subscription would silently give away every month that a build took
         * longer than a sweep interval.
         */
        [$customer] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $this->serviceFor($customer, [
            'subscription_id' => $subscription->getKey(),
            'status' => ServiceStatus::Pending,
            'resources' => ['vcpu' => 2],
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));

        $sweep = app(RenewDueSubscriptions::class)->execute();

        $this->assertSame(1, $sweep->renewed);
        $this->assertSame(1, Invoice::query()->count());
    }

    #[Test]
    public function a_skipped_renewal_says_so_where_an_operator_can_read_it(): void
    {
        /*
         * A subscription that is skipped and says nothing is a subscription
         * that stops billing silently. The sweep's `skipped` count is a
         * number with no names in it, so the reason is written down beside
         * the subscription, the customer and the service it is waiting on.
         */
        [$customer] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $service = $this->serviceFor($customer, [
            'subscription_id' => $subscription->getKey(),
            'status' => ServiceStatus::Pending,
            'resources' => ['placement_blocked_reason' => 'no single IP pool, and the plan names none'],
        ]);

        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->once()
            ->with(
                'A renewal was skipped: the service it pays for was never delivered.',
                Mockery::on(static fn (array $context): bool => $context['subscription_id'] === (string) $subscription->getKey()
                    && $context['service_id'] === (string) $service->getKey()
                    && $context['reason'] === 'no single IP pool, and the plan names none'),
            );

        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));

        $sweep = app(RenewDueSubscriptions::class)->execute();

        // The positive control: the sweep really did reach this subscription.
        $this->assertSame(1, $sweep->considered);
        $this->assertSame(1, $sweep->skipped);
    }

    // ---- the operator surface --------------------------------------------

    #[Test]
    public function the_operator_index_names_the_blocked_reason_and_hides_no_row(): void
    {
        [$stuckCustomer] = $this->accountWithOwner();
        [$wellCustomer] = $this->accountWithOwner();

        $stuck = $this->serviceFor($stuckCustomer, [
            'status' => ServiceStatus::Pending,
            'resources' => ['vcpu' => 2, 'placement_blocked_reason' => 'the plan names no hosting package, so no panel quota can be applied'],
        ]);

        $well = $this->serviceFor($wellCustomer, ['status' => ServiceStatus::Active, 'activated_at' => now()]);

        // The filter an operator actually wants: exactly the stuck one.
        $filtered = $this->actingAs($this->operator())
            ->getJson('/api/admin/services?blocked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $stuck->getKey())
            ->assertJsonPath(
                'data.0.placement_blocked_reason',
                'the plan names no hosting package, so no panel quota can be applied',
            );

        $this->assertSame([$stuck->id], array_column((array) $filtered->json('data'), 'id'));

        // And nothing is hidden by the existence of the filter: unfiltered,
        // both rows are there and the healthy one simply has no reason.
        $all = $this->actingAs($this->operator())->getJson('/api/admin/services')->assertOk();

        $ids = array_column((array) $all->json('data'), 'id');
        $this->assertContains($stuck->id, $ids);
        $this->assertContains($well->id, $ids);

        $reasons = array_column((array) $all->json('data'), 'placement_blocked_reason', 'id');
        $this->assertNull($reasons[$well->id]);
    }

    #[Test]
    public function a_customer_cannot_read_the_operator_index(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->serviceFor($customer, [
            'status' => ServiceStatus::Pending,
            'resources' => ['placement_blocked_reason' => 'no single active compute cluster, and the plan names none'],
        ]);

        // A perfectly legitimate portal login, asking for the staff surface.
        $this->actingAs($user)->getJson('/api/admin/services?blocked=1')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/services')->assertForbidden();
    }

    #[Test]
    public function the_reason_is_not_published_on_the_customers_own_copy_of_the_row(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = $this->serviceFor($mine, [
            'status' => ServiceStatus::Pending,
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'placement_blocked_reason' => 'no single IP pool, and the plan names none'],
        ]);

        $hers = $this->serviceFor($theirs, [
            'status' => ServiceStatus::Pending,
            'resources' => ['vcpu' => 8, 'placement_blocked_reason' => 'no single active compute cluster, and the plan names none'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/services')->assertOk();

        // The row is not hidden — the customer sees the service they bought.
        $this->assertSame([$ours->id], array_column((array) $response->json('data'), 'id'));

        // What they do not see is the operator's note about internal topology,
        // on their own row or on anybody else's.
        $resources = (array) $response->json('data.0.resources');
        $this->assertArrayNotHasKey('placement_blocked_reason', $resources);
        $this->assertEquals(['vcpu' => 2, 'memory_mib' => 4096], $resources);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('IP pool', $body);
        $this->assertStringNotContainsString('compute cluster', $body);
        $this->assertStringNotContainsString($hers->id, $body);

        // The same single row, fetched directly, answers the same way.
        $single = $this->actingAs($user)->getJson('/api/v1/services/'.$ours->id)->assertOk();
        $this->assertArrayNotHasKey('placement_blocked_reason', (array) $single->json('data.resources'));
    }

    // ---- fixtures ---------------------------------------------------------

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function subscriptionFor(Customer $customer): Subscription
    {
        return Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-05-01 00:00:00'), BillingPeriod::Monthly)
            ->priced(9_000)
            ->create(['customer_id' => $customer->getKey()]);
    }

    private function hostingPlan(bool $withPackage): Plan
    {
        $product = Product::factory()->create(['kind' => ProductKind::SharedHosting->value]);
        $plan = Plan::factory()->create(['product_id' => $product->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);

        if ($withPackage) {
            HostingPackage::factory()->create(['plan_id' => $plan->getKey()]);
        }

        return $plan->fresh(['prices', 'product']);
    }
}
