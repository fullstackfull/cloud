<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Tests\TestCase;

/**
 * Shared scaffolding for the billing endpoints.
 *
 * Two customers with two logins appear in almost every test here on purpose:
 * the interesting question about a billing API is not whether it can show you
 * your own invoices, it is whether it can be talked into showing you somebody
 * else's — or into cancelling their subscription.
 */
abstract class BillingApiTestCase extends TestCase
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
     * Two accounts the *same* login belongs to, and that login.
     *
     * This is the shape the other cross-tenant tests cannot reach. They use an
     * attacker with no membership at all in the victim account, so they would
     * still pass against an implementation that scoped to "every account this
     * user belongs to" rather than to the one account the request is acting
     * for. Here both accounts are the caller's own, the header names one, and
     * the other must be as unreachable as a stranger's.
     *
     * @return array{0: Customer, 1: Customer, 2: User}
     */
    protected function twoAccountsOneLogin(): array
    {
        $acting = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $other = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $user = $this->memberOf($acting, CustomerRole::Owner);
        $this->memberOf($other, CustomerRole::Owner, $user);

        return [$acting, $other, $user];
    }

    /**
     * An invoice that belongs to a given account.
     *
     * The customer is passed rather than left to the factory to invent, since
     * every test here turns on which account a document belongs to.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function invoiceFor(Customer $customer, array $attributes = []): Invoice
    {
        return Invoice::factory()->create(['customer_id' => $customer->id] + $attributes);
    }

    /**
     * A subscription that belongs to a given account.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function subscriptionFor(Customer $customer, array $attributes = []): Subscription
    {
        return Subscription::factory()->create(['customer_id' => $customer->id] + $attributes);
    }
}
