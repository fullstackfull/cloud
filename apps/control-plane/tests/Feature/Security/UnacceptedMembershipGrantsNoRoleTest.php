<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: a customer_members row that had been invited but never accepted
 * already conferred the full role. User::roleWithin() returned the pivot's role
 * without consulting accepted_at, and the customers/users relations loaded
 * unaccepted rows, so /api/v1/me served the target account's details to someone
 * who had merely been named in an invitation.
 */
final class UnacceptedMembershipGrantsNoRoleTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function inviteOnly(Customer $customer, User $user, CustomerRole $role): void
    {
        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'invited_at' => now(),
            'accepted_at' => null,
        ]);
    }

    #[Test]
    public function an_invitee_who_never_accepted_holds_no_role(): void
    {
        $customer = Customer::factory()->create(['display_name' => 'Victim Ltd']);
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $this->inviteOnly($customer, $invitee, CustomerRole::Administrator);

        $membership = $invitee->memberships()->first();
        $this->assertNotNull($membership);
        $this->assertFalse($membership->isAccepted());

        $this->assertNull($invitee->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function accepting_the_invitation_is_what_confers_the_role(): void
    {
        $customer = Customer::factory()->create();
        $invitee = User::factory()->create();

        $this->inviteOnly($customer, $invitee, CustomerRole::Administrator);

        $invitee->memberships()->first()->forceFill(['accepted_at' => now()])->save();

        $this->assertSame(
            CustomerRole::Administrator,
            $invitee->fresh()->roleWithin((string) $customer->getKey()),
        );
    }

    #[Test]
    public function an_unaccepted_membership_is_not_serialised_by_the_me_endpoint(): void
    {
        $customer = Customer::factory()->create(['display_name' => 'Victim Ltd']);

        $invitee = User::factory()->create([
            'email' => 'invitee@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->inviteOnly($customer, $invitee, CustomerRole::Administrator);

        $this->postJson(route('api.v1.login'), [
            'email' => $invitee->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonCount(0, 'data.customers');
    }

    #[Test]
    public function an_unaccepted_membership_does_not_appear_in_the_customers_member_list(): void
    {
        $customer = Customer::factory()->create();
        $invitee = User::factory()->create();

        $this->inviteOnly($customer, $invitee, CustomerRole::Administrator);

        $this->assertCount(0, $customer->users()->get());

        // The row itself is still there — the invitation exists, it simply is
        // not a grant until it is accepted.
        $this->assertCount(1, $customer->members()->get());
    }
}
