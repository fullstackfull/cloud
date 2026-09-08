<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole of an invitation, from the offer to the membership.
 */
final class InvitingAColleagueTest extends TeamApiTestCase
{
    #[Test]
    public function an_owner_invites_somebody_and_the_offer_goes_to_their_address(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', [
                'email' => 'Newcomer@Example.test',
                'role' => CustomerRole::Technical->value,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'newcomer@example.test')
            ->assertJsonPath('data.role', 'technical')
            ->assertJsonPath('data.status', 'pending');

        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('newcomer@example.test'));
    }

    #[Test]
    public function the_response_never_carries_the_token_that_would_redeem_it(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();

        $created = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'a@example.test', 'role' => 'member']);

        $listed = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/team/invitations');

        /** @var CustomerInvitation $invitation */
        $invitation = CustomerInvitation::query()->firstOrFail();

        // Neither the token nor the hash of it. An invitation that could be
        // read off the team screen would let anyone who can see the screen
        // join as anyone who has been invited.
        foreach ([$created->getContent(), $listed->getContent()] as $body) {
            $this->assertStringNotContainsString($invitation->token_hash, (string) $body);
            $this->assertStringNotContainsString('token', (string) $body);
        }
    }

    #[Test]
    public function inviting_an_address_that_already_has_a_login_looks_exactly_like_inviting_one_that_does_not(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'known@example.test', 'role' => 'member']);

        $unknown = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'stranger@example.test', 'role' => 'member']);

        $known->assertCreated();
        $unknown->assertCreated();

        // The bodies differ only in the address that was asked about and the
        // ids and timestamps the platform minted. Nothing distinguishes a
        // Lynomia customer from a stranger, which is exactly the fact an
        // attacker would be fishing for.
        $this->assertSame(
            array_keys((array) $known->json('data')),
            array_keys((array) $unknown->json('data')),
        );
        $this->assertSame($known->json('data.status'), $unknown->json('data.status'));
    }

    #[Test]
    public function the_invited_person_accepts_with_the_token_from_their_mail_and_becomes_a_member(): void
    {
        [$customer] = $this->accountWithOwner();
        $token = str_repeat('a', 64);

        $invitation = CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'joiner@example.test',
            'role' => CustomerRole::Billing,
        ]);

        $joiner = User::factory()->create(['email' => 'joiner@example.test', 'email_verified_at' => now()]);

        $this->actingAs($joiner)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertCreated()
            ->assertJsonPath('data.role', 'billing');

        $this->assertDatabaseHas('customer_members', [
            'customer_id' => $customer->getKey(),
            'user_id' => $joiner->getKey(),
            'role' => 'billing',
        ]);

        $this->assertNotNull($invitation->fresh()?->accepted_at);
        $this->assertSame(
            (string) CustomerMember::query()->where('user_id', $joiner->getKey())->firstOrFail()->getKey(),
            (string) $invitation->fresh()?->accepted_member_id,
        );
    }

    #[Test]
    public function the_same_token_cannot_be_used_twice(): void
    {
        [$customer] = $this->accountWithOwner();
        $token = str_repeat('b', 64);

        CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'twice@example.test',
        ]);

        $joiner = User::factory()->create(['email' => 'twice@example.test', 'email_verified_at' => now()]);

        $this->actingAs($joiner)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();
        $this->actingAs($joiner)->postJson("/api/v1/invitations/{$token}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_not_open');

        $this->assertSame(1, CustomerMember::query()->where('user_id', $joiner->getKey())->count());
    }

    #[Test]
    public function a_forwarded_invitation_admits_nobody(): void
    {
        [$customer] = $this->accountWithOwner();
        $token = str_repeat('c', 64);

        CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'intended@example.test',
        ]);

        $someoneElse = User::factory()->create(['email' => 'forwarded@example.test', 'email_verified_at' => now()]);

        $this->actingAs($someoneElse)->postJson("/api/v1/invitations/{$token}/accept")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'membership.invitation_not_yours');

        $this->assertDatabaseCount('customer_members', 1);
    }

    #[Test]
    public function an_unverified_address_cannot_walk_into_the_account_it_was_invited_to(): void
    {
        [$customer] = $this->accountWithOwner();
        $token = str_repeat('d', 64);

        CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'unverified@example.test',
        ]);

        $joiner = User::factory()->create(['email' => 'unverified@example.test', 'email_verified_at' => null]);

        $this->actingAs($joiner)->postJson("/api/v1/invitations/{$token}/accept")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'membership.address_not_verified');
    }

    #[Test]
    public function an_expired_offer_and_a_token_that_names_nothing_answer_identically(): void
    {
        [$customer] = $this->accountWithOwner();
        $expiredToken = str_repeat('e', 64);
        $inventedToken = str_repeat('f', 64);

        CustomerInvitation::factory()->withToken($expiredToken)->expired()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'late@example.test',
        ]);

        $user = User::factory()->create(['email' => 'late@example.test', 'email_verified_at' => now()]);

        $expired = $this->actingAs($user)->getJson("/api/v1/invitations/{$expiredToken}");
        $invented = $this->actingAs($user)->getJson("/api/v1/invitations/{$inventedToken}");

        $this->assertSame($expired->status(), $invented->status());
        $this->assertSame($expired->json('error.code'), $invented->json('error.code'));
    }

    #[Test]
    public function a_revoked_offer_stops_working_even_though_the_mail_still_says_otherwise(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();
        $token = str_repeat('1', 64);

        $invitation = CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'revoked@example.test',
        ]);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->deleteJson("/api/v1/team/invitations/{$invitation->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $user = User::factory()->create(['email' => 'revoked@example.test', 'email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(409);
        $this->assertDatabaseCount('customer_members', 1);
    }

    #[Test]
    public function resending_replaces_the_link_rather_than_repeating_it(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();
        $original = str_repeat('2', 64);

        $invitation = CustomerInvitation::factory()->withToken($original)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'resend@example.test',
        ]);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/team/invitations/{$invitation->getKey()}/resend")
            ->assertOk()
            ->assertJsonPath('data.sent_count', 2);

        // The platform stores a hash, so it cannot repeat a token it never
        // kept. One live link per offer, always the most recent.
        $this->assertNotSame(
            CustomerInvitation::hashOf($original),
            (string) $invitation->fresh()?->token_hash,
        );

        $user = User::factory()->create(['email' => 'resend@example.test', 'email_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/v1/invitations/{$original}/accept")->assertStatus(409);
    }

    #[Test]
    public function two_offers_to_the_same_address_cannot_both_be_open(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'dup@example.test', 'role' => 'member'])
            ->assertCreated();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'dup@example.test', 'role' => 'billing'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_already_open');

        $this->assertDatabaseCount('customer_invitations', 1);
    }

    #[Test]
    public function nobody_can_be_invited_as_the_owner(): void
    {
        Mail::fake();
        [$customer, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => 'usurper@example.test', 'role' => 'owner'])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_invitations', 0);
    }

    #[Test]
    public function declining_closes_the_offer_and_creates_no_membership(): void
    {
        [$customer] = $this->accountWithOwner();
        $token = str_repeat('3', 64);

        CustomerInvitation::factory()->withToken($token)->create([
            'customer_id' => $customer->getKey(),
            'email' => 'nothanks@example.test',
        ]);

        $user = User::factory()->create(['email' => 'nothanks@example.test', 'email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/invitations/{$token}/decline")->assertNoContent();

        $this->assertDatabaseCount('customer_members', 1);
        $this->assertNotNull(CustomerInvitation::query()->firstOrFail()->declined_at);
    }
}
