<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * One colleague, from the offer to the day their access is taken away.
 *
 * The tests around this one prove each rule on its own, each from a state a
 * factory arranged. This proves the sequence, from whatever the previous step
 * actually left behind — because the failures worth catching here are the ones
 * that only exist between steps: a permission cached from the request that
 * granted it, a membership row a removal left half-written, a token that still
 * works after the person who redeemed it is gone.
 *
 * Each request is its own — a fresh authentication, resolving the account
 * again — because "the permission changes immediately" is a claim about the
 * *next* request, and a test that reused one would prove nothing about it.
 */
final class TheWholeLifeOfATeammateTest extends TeamApiTestCase
{
    #[Test]
    public function somebody_joins_an_account_changes_role_and_is_removed_from_it(): void
    {
        Mail::fake();

        [$customer, $owner] = $this->accountWithOwner();

        /* ---------------------------------------------------------------
         | 1. The owner invites a technical contact.
         */
        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', [
                'email' => 'engineer@example.test',
                'role' => CustomerRole::Technical->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // The token exists only in the mail. Taking it from there rather than
        // from the database is the point: if the link a person receives does
        // not work, nothing else in this test matters.
        $token = $this->tokenFromTheMail();

        /* ---------------------------------------------------------------
         | 2. They accept, and the offer becomes a membership.
         */
        $engineer = User::factory()->create([
            'email' => 'engineer@example.test',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($engineer)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertCreated()
            ->assertJsonPath('data.role', CustomerRole::Technical->value);

        /* ---------------------------------------------------------------
         | 3. Technical access works.
         */
        $this->actingAs($engineer)
            ->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/vps')
            ->assertOk();

        $this->actingAs($engineer)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/me/api-tokens', [
                'name' => 'deploy key',
                'abilities' => ['service.view'],
                // Issuing a token is a credential-creating act, so the
                // endpoint asks for the password again whoever you are.
                'current_password' => 'password',
            ])
            ->assertCreated();

        /* ---------------------------------------------------------------
         | 4. And the money is not theirs to see.
         */
        $this->actingAs($engineer)
            ->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/invoices')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        /* ---------------------------------------------------------------
         | 5. The owner moves them to billing. Nothing else happens — no
         |    second acceptance, no new invitation.
         */
        $member = $this->membership($customer, $engineer);

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->patchJson("/api/v1/team/members/{$member->getKey()}", ['role' => CustomerRole::Billing->value])
            ->assertOk()
            ->assertJsonPath('data.role', CustomerRole::Billing->value);

        /* ---------------------------------------------------------------
         | 6. The very next request has the new permissions, in both
         |    directions. A role change that only took effect on the next
         |    sign-in would leave somebody demoted for abuse still able to act.
         |
         |    `fresh()` because this one PHP object survives between calls,
         |    which no real request does: a browser sends a cookie and the
         |    application builds the user again from the row. Re-reading it
         |    here is what makes the next assertion about the platform rather
         |    than about a model instance a test happened to keep.
         */
        $engineer = $engineer->fresh();
        self::assertNotNull($engineer);

        $this->actingAs($engineer)
            ->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/invoices')
            ->assertOk();

        $this->actingAs($engineer)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/me/api-tokens', [
                'name' => 'second key',
                'abilities' => ['service.view'],
                'current_password' => 'password',
            ])
            ->assertForbidden();

        /* ---------------------------------------------------------------
         | 7. They leave, and the account closes behind them.
         */
        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->deleteJson("/api/v1/team/members/{$member->getKey()}")
            ->assertNoContent();

        /*
         * The same answer a stranger gets, in the same words and under the
         * same code: an account that does not exist and an account this login
         * is not in are indistinguishable, so nobody can enumerate customer
         * ids by watching which ones answer differently.
         *
         * Two endpoints, because the refusal is the middleware's rather than
         * either controller's — the account is never resolved, so no
         * permission is ever consulted.
         */
        $engineer = $engineer->fresh();
        self::assertNotNull($engineer);

        foreach (['/api/v1/invoices', '/api/v1/vps'] as $path) {
            $this->actingAs($engineer)
                ->withHeaders($this->actingFor($customer))
                ->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'tenancy.account_unavailable');
        }

        $this->assertDatabaseMissing('customer_members', [
            'customer_id' => $customer->getKey(),
            'user_id' => $engineer->getKey(),
        ]);

        /* ---------------------------------------------------------------
         | 8. And the link that let them in the first time does not let
         |    them back.
         */
        /*
         * "No longer open" rather than "no such invitation": the token was
         * spent, and the record of it stays. A token that named nothing at all
         * answers 404 instead, which distinguishes a spent token from an
         * invented one — acceptable only because the token is 64 random
         * characters and there is nothing to enumerate.
         */
        $this->actingAs($engineer)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_not_open');

        $this->assertDatabaseMissing('customer_members', [
            'customer_id' => $customer->getKey(),
            'user_id' => $engineer->getKey(),
        ]);
    }

    /**
     * The token out of the invitation mail's accept link.
     */
    private function tokenFromTheMail(): string
    {
        $token = null;

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token): bool {
            $token = substr((string) strrchr($mail->acceptUrl, '/'), 1);

            return true;
        });

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('The invitation mail carried no token to accept with.');
        }

        return $token;
    }
}
