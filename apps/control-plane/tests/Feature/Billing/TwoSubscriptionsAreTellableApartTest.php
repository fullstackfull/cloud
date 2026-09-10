<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

/**
 * A subscription says what it is for.
 *
 * The defect (AR-9): two monthly subscriptions at the same price showed as two
 * identical rows — "9.000 KWD, renews on the 1st" twice — with nothing to say
 * which one ran the machine the customer still needed. Cancelling one was a
 * coin toss with a production server on the other side of it.
 */
final class TwoSubscriptionsAreTellableApartTest extends BillingApiTestCase
{
    #[Test]
    public function each_subscription_names_its_plan_its_product_and_the_machine_it_runs(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $product = Product::factory()->create([
            'name' => ['en' => 'Cloud VPS', 'ar' => 'خدمة سحابية'],
            'kind' => 'vps',
        ]);

        $starter = Plan::factory()->create([
            'product_id' => $product->id,
            'name' => ['en' => 'Starter', 'ar' => 'المبتدئة'],
        ]);

        $subscription = $this->subscriptionFor($customer, ['plan_id' => $starter->id]);

        Service::factory()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $starter->id,
            'kind' => 'vps',
            'label' => 'Cloud VPS — Starter',
            'status' => ServiceStatus::Active,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/subscriptions')->assertOk();

        $this->assertSame('Starter', $response->json('data.0.plan.name'));
        $this->assertSame('Cloud VPS', $response->json('data.0.product.name'));
        $this->assertSame('vps', $response->json('data.0.product.kind'));
        $this->assertSame('Cloud VPS — Starter', $response->json('data.0.services.0.label'));
        $this->assertSame('vps', $response->json('data.0.services.0.kind'));

        // And in Arabic, because the customer chose Arabic.
        $arabic = $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/subscriptions')
            ->assertOk();

        $this->assertSame('المبتدئة', $arabic->json('data.0.plan.name'));
        $this->assertSame('خدمة سحابية', $arabic->json('data.0.product.name'));
    }

    #[Test]
    public function two_identical_looking_subscriptions_carry_different_identities(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $plan = Plan::factory()->create(['name' => ['en' => 'Starter']]);

        $first = $this->subscriptionFor($customer, ['plan_id' => $plan->id]);
        $second = $this->subscriptionFor($customer, ['plan_id' => $plan->id]);

        foreach ([[$first, 'web-01'], [$second, 'db-01']] as [$subscription, $hostname]) {
            $service = Service::factory()->create([
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'kind' => 'vps',
                'status' => ServiceStatus::Active,
            ]);

            // The machine's own name, in the module that owns machines.
            VirtualMachine::factory()->create([
                'service_id' => $service->id,
                'hostname' => $hostname,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/subscriptions')->assertOk();

        $identities = collect((array) $response->json('data'))
            ->map(fn (array $row): ?string => $row['services'][0]['identity'] ?? null)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['db-01', 'web-01'], $identities);
    }

    #[Test]
    public function a_service_that_is_still_being_created_says_so_rather_than_inventing_a_name(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $subscription = $this->subscriptionFor($customer);

        Service::factory()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/subscriptions')->assertOk();

        $this->assertNull($response->json('data.0.services.0.identity'));
        $this->assertFalse($response->json('data.0.services.0.is_usable'));
    }

    #[Test]
    public function the_price_is_the_subscriptions_own_and_not_what_the_plan_costs_today(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $plan = Plan::factory()->create(['name' => ['en' => 'Starter']]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            // The catalogue put the price up after this customer subscribed.
            'recurring_amount_minor' => 15000,
        ]);

        $this->subscriptionFor($customer, [
            'plan_id' => $plan->id,
            'recurring_amount_minor' => 9000,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/subscriptions')->assertOk();

        $this->assertSame(9000, $response->json('data.0.recurring_amount.minor_units'));
        $this->assertSame('KWD', $response->json('data.0.recurring_amount.currency'));
    }

    #[Test]
    public function another_accounts_subscription_is_a_404_and_not_a_403(): void
    {
        [$acting, $other, $user] = $this->twoAccountsOneLogin();

        $theirs = $this->subscriptionFor($other);

        // Acting for the first account, asking for the second account's row by
        // id. A 403 would confirm the row exists.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', (string) $acting->id)
            ->getJson("/api/v1/subscriptions/{$theirs->id}")
            ->assertNotFound();
    }
}
