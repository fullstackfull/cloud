<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

/**
 * Shared scaffolding for the order endpoints.
 *
 * Two customers with two logins exist in almost every test here on purpose:
 * the interesting question about an order API is not whether it can show you
 * yours, it is whether it can be talked into showing you somebody else's.
 */
abstract class OrdersApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer account with an accepted owner membership, and the user who
     * holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = $this->memberOf($customer, $role);

        return [$customer, $user];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * A plan that is actually sellable: active product, active plan, and a
     * price in KWD for the period under test.
     */
    protected function publishedPlan(int $monthlyMinor = 9000, int $setupMinor = 0): Plan
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => $setupMinor,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    /**
     * @param  list<array{plan_id: string, quantity: int}>|null  $items
     * @return array<string, mixed>
     */
    protected function basket(Plan $plan, ?array $items = null): array
    {
        return [
            'items' => $items ?? [['plan_id' => $plan->id, 'quantity' => 1]],
            'billing_period' => BillingPeriod::Monthly->value,
        ];
    }
}
