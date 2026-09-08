<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Tests\TestCase;

abstract class SupportTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

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

    /**
     * A member of staff with the support role and nothing else.
     *
     * Not a super admin: every permission check passes for one, so a boundary
     * test written against a super admin proves that the endpoint exists and
     * nothing about who may reach it.
     */
    protected function supportAgent(Role $role = Role::Support): User
    {
        $this->seedRoles();

        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function actingFor(Customer $customer): array
    {
        return ['X-Lynomia-Customer' => (string) $customer->getKey()];
    }
}
