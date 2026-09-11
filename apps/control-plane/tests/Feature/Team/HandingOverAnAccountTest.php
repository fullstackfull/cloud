<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ownership: one per account, transferred rather than granted.
 */
final class HandingOverAnAccountTest extends TeamApiTestCase
{
    #[Test]
    public function the_accounts_own_id_is_no_longer_what_confirms_the_transfer(): void
    {
        /*
         * The confirmation used to be the account's ULID, which is unique and
         * unreadable — nobody types twenty-six characters of base32, they copy
         * them, and a copied value is not a moment of recognition. It is now
         * the account's name.
         *
         * Asserted from the other direction as well as the right one, because
         * "the new value works" would also pass if the server had stopped
         * checking.
         */
        [$customer, $owner] = $this->accountWithOwner();
        $successor = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $successor);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $membership->getKey(),
                'confirm_account_name' => (string) $customer->getKey(),
            ])
            ->assertStatus(422);

        // And nothing moved.
        $this->assertSame(CustomerRole::Owner, $owner->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function whitespace_a_copy_leaves_behind_is_forgiven_and_nothing_else_is(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $successor = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $successor);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $membership->getKey(),
                'confirm_account_name' => '  '.$customer->display_name.'  ',
            ])
            ->assertOk();

        $this->assertSame(CustomerRole::Owner, $successor->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function the_owner_hands_over_and_becomes_an_administrator_in_the_same_act(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $successor = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $successor);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $membership->getKey(),
                'confirm_account_name' => $customer->display_name,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame(CustomerRole::Owner, $successor->fresh()?->roleWithin((string) $customer->getKey()));
        $this->assertSame(CustomerRole::Administrator, $owner->fresh()?->roleWithin((string) $customer->getKey()));

        // Never two owners, and never none.
        $this->assertSame(1, CustomerMember::query()
            ->where('customer_id', $customer->getKey())
            ->where('role', 'owner')
            ->count());
    }

    #[Test]
    public function an_administrator_cannot_hand_the_account_to_themselves(): void
    {
        [$customer] = $this->accountWithOwner();
        $ambitious = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $ambitious);

        $this->actingAs($ambitious)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $membership->getKey(),
                'confirm_account_name' => $customer->display_name,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'membership.only_the_owner_may_transfer');

        $this->assertSame(CustomerRole::Administrator, $ambitious->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function the_account_id_has_to_be_typed_back(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $successor = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $successor);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $membership->getKey(),
                'confirm_account_name' => 'Some Other Company',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'membership.transfer_not_confirmed');

        $this->assertSame(CustomerRole::Owner, $owner->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function ownership_cannot_be_handed_to_somebody_who_has_not_joined(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $invitedButNotJoined = User::factory()->create();
        /** @var CustomerMember $pending */
        $pending = $customer->members()->create([
            'user_id' => $invitedButNotJoined->id,
            'role' => CustomerRole::Administrator,
            'invited_at' => now(),
            // Never accepted: an offer, not a grant.
            'accepted_at' => null,
        ]);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/transfer-ownership', [
                'member_id' => (string) $pending->getKey(),
                'confirm_account_name' => $customer->display_name,
            ])
            ->assertNotFound();

        $this->assertSame(CustomerRole::Owner, $owner->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function the_owner_cannot_be_removed_or_demoted_by_anybody_including_themselves(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $membership = $this->membership($customer, $owner);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->patchJson("/api/v1/team/members/{$membership->getKey()}", ['role' => 'administrator'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.owner_cannot_demote_themselves');

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->deleteJson("/api/v1/team/members/{$membership->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.owner_cannot_be_removed');

        $this->assertSame(CustomerRole::Owner, $owner->fresh()?->roleWithin((string) $customer->getKey()));
    }

    #[Test]
    public function no_member_can_be_promoted_to_owner_through_the_role_endpoint(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        $membership = $this->membership($customer, $member);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->patchJson("/api/v1/team/members/{$membership->getKey()}", ['role' => 'owner'])
            ->assertStatus(422);

        $this->assertSame(1, CustomerMember::query()
            ->where('customer_id', $customer->getKey())
            ->where('role', 'owner')
            ->count());
    }
}
