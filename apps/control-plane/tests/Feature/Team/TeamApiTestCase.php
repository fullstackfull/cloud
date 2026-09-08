<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

abstract class TeamApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(): array
    {
        $customer = Customer::factory()->organization()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, CustomerRole::Owner)];
    }

    protected function memberOf(
        Customer $customer,
        CustomerRole $role = CustomerRole::Member,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    protected function membership(Customer $customer, User $user): CustomerMember
    {
        /** @var CustomerMember $member */
        $member = CustomerMember::query()
            ->where('customer_id', $customer->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        return $member;
    }

    /**
     * The header that names which account a request acts for. Only needed when
     * the caller belongs to more than one, but harmless otherwise and written
     * everywhere so that adding a second account to a test does not silently
     * change what an earlier assertion was testing.
     *
     * @return array<string, string>
     */
    protected function actingFor(Customer $customer): array
    {
        return ['X-Lynomia-Customer' => (string) $customer->getKey()];
    }
}
