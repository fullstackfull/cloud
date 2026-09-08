<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The five isolation properties a multi-user account has to have, each stated
 * as the thing that would be true if it did not.
 */
final class OneAccountCannotReachAnotherTest extends TeamApiTestCase
{
    #[Test]
    public function a_member_of_one_account_sees_nothing_of_another(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs, $them] = $this->accountWithOwner();

        $this->memberOf($theirs, CustomerRole::Technical);

        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->getJson('/api/v1/team/members')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Naming their account in the header is refused rather than honoured:
        // membership is checked before anything is read.
        $this->actingAs($me)->withHeaders($this->actingFor($theirs))
            ->getJson('/api/v1/team/members')
            ->assertStatus(403);

        $this->assertNotSame((string) $me->getKey(), (string) $them->getKey());
    }

    #[Test]
    public function a_member_id_from_another_account_is_not_found_rather_than_forbidden(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $stranger = $this->memberOf($theirs, CustomerRole::Member);
        $strangersMembership = $this->membership($theirs, $stranger);

        // 404, not 403. A forbidden would confirm the id names a real
        // membership somewhere, which is a confirmation nobody outside that
        // account should be able to buy.
        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->deleteJson("/api/v1/team/members/{$strangersMembership->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('customer_members', ['id' => $strangersMembership->getKey()]);
    }

    #[Test]
    public function a_removed_member_loses_access_and_their_key_to_the_account(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $leaver = $this->memberOf($customer, CustomerRole::Technical);
        $membership = $this->membership($customer, $leaver);

        $token = $leaver->createToken('theirs', ['service:read']);
        PersonalAccessToken::query()->whereKey($token->accessToken->getKey())
            ->update(['customer_id' => $customer->getKey()]);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->deleteJson("/api/v1/team/members/{$membership->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_members', ['id' => $membership->getKey()]);
        // The token would already be refused — ResolveActingCustomer re-checks
        // membership on every request — but a credential that is refused on
        // use is still a credential somebody holds.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);

        $this->actingAs($leaver->fresh())->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/members')
            ->assertStatus(403);
    }

    #[Test]
    public function a_downgrade_takes_effect_on_the_very_next_request(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();
        $admin = $this->memberOf($customer, CustomerRole::Administrator);
        $membership = $this->membership($customer, $admin);

        $this->actingAs($admin)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'before@example.test', 'role' => 'member'])
            ->assertCreated();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->patchJson("/api/v1/team/members/{$membership->getKey()}", ['role' => 'member'])
            ->assertOk()
            ->assertJsonPath('data.role', 'member');

        // No sign-out, no cache expiry, no waiting: the role is read from the
        // membership row on every request.
        $this->actingAs($admin->fresh())->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'after@example.test', 'role' => 'member'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_billing_member_may_pay_and_may_not_touch_the_machines(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $finance = $this->memberOf($customer, CustomerRole::Billing);

        $this->assertTrue(CustomerRole::Billing->can('billing.pay'));
        $this->assertFalse(CustomerRole::Billing->can('service.manage'));
        $this->assertFalse(CustomerRole::Billing->can('customer.members.manage'));

        $this->actingAs($finance)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/wallet')
            ->assertOk();

        $this->actingAs($finance)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'nope@example.test', 'role' => 'member'])
            ->assertStatus(403);

        $this->assertNotSame((string) $finance->getKey(), (string) $owner->getKey());
    }

    #[Test]
    public function a_technical_member_may_touch_the_machines_and_may_not_spend(): void
    {
        [$customer] = $this->accountWithOwner();
        $engineer = $this->memberOf($customer, CustomerRole::Technical);

        $this->assertTrue(CustomerRole::Technical->can('service.manage'));
        $this->assertFalse(CustomerRole::Technical->can('billing.pay'));
        $this->assertFalse(CustomerRole::Technical->can('billing.view'));
        // Ending a service is the end of something the account pays for.
        $this->assertFalse(CustomerRole::Technical->can('service.destroy'));

        $this->actingAs($engineer)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/wallet')
            ->assertStatus(403);
    }

    #[Test]
    public function the_read_only_member_can_see_and_change_nothing(): void
    {
        [$customer] = $this->accountWithOwner();
        $watcher = $this->memberOf($customer, CustomerRole::Member);

        $this->assertTrue(CustomerRole::Member->can('service.view'));
        foreach (['service.manage', 'service.destroy', 'billing.pay', 'customer.members.manage', 'customer.manage'] as $forbidden) {
            $this->assertFalse(CustomerRole::Member->can($forbidden), $forbidden.' must not be a read-only permission');
        }

        $this->actingAs($watcher)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/members')
            ->assertOk();

        $this->actingAs($watcher)->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/invitations')
            ->assertStatus(403);
    }

    #[Test]
    public function an_invitation_from_one_account_cannot_be_revoked_by_another(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $invitation = CustomerInvitation::factory()->create(['customer_id' => $theirs->getKey()]);

        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->deleteJson("/api/v1/team/invitations/{$invitation->getKey()}")
            ->assertNotFound();

        $this->assertNull($invitation->fresh()?->revoked_at);
    }

    #[Test]
    public function joining_a_second_account_does_not_widen_the_first(): void
    {
        $first = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $second = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $person = User::factory()->create(['email_verified_at' => now()]);
        $this->memberOf($first, CustomerRole::Owner, $person);
        $this->memberOf($second, CustomerRole::Member, $person);

        $this->assertSame(CustomerRole::Owner, $person->fresh()?->roleWithin((string) $first->getKey()));
        $this->assertSame(CustomerRole::Member, $person->fresh()?->roleWithin((string) $second->getKey()));

        // Owning one account does not make them an owner of the other.
        $this->actingAs($person)->withHeaders($this->actingFor($second))
            ->getJson('/api/v1/team/invitations')
            ->assertStatus(403);
    }
}
