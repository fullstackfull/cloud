<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer cannot write a field the platform did not offer them.
 *
 * Every customer write goes through a validator and is applied from
 * `validated()` or from named accessors, so the allow-list is the rule set and
 * nothing outside it reaches a model. That is the design, it is easy to read,
 * and it is exactly the kind of claim that stays true right up until somebody
 * writes `$model->update($request->all())` in a hurry.
 *
 * So it is asserted from the outside, by posting the fields an attacker would
 * try. Each test sends the legitimate payload *plus* internal fields and then
 * checks the stored row rather than the response: a field that was accepted
 * and then hidden from the response would pass a body assertion and still be a
 * privilege escalation.
 *
 * Frontend omission is not a control and is not what is being tested here. The
 * portal does not send these fields; that is why the server must refuse them.
 */
final class CustomerWritesResistOverpostingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['name' => 'Owner', 'timezone' => 'Asia/Kuwait']);

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    #[Test]
    public function the_profile_ignores_every_field_it_did_not_offer(): void
    {
        [, $user] = $this->account();

        $originalEmail = $user->email;

        $this->actingAs($user)->patchJson('/api/v1/me', [
            // The four the endpoint offers.
            'name' => 'Renamed Properly',
            'locale' => 'ar',
            'timezone' => 'Europe/London',
            'phone' => '+965 1234 5678',

            // And the ones it does not. Changing an address here would skip
            // re-verification; the rest are privilege or identity.
            'email' => 'attacker@example.test',
            'email_verified_at' => now()->toIso8601String(),
            'password' => 'not-a-password-change',
            'two_factor_confirmed_at' => now()->toIso8601String(),
            'is_admin' => true,
            'id' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
        ])->assertOk();

        $fresh = $user->fresh();
        self::assertNotNull($fresh);

        // What was offered was applied.
        self::assertSame('Renamed Properly', $fresh->name);
        self::assertSame('Europe/London', $fresh->timezone);

        // And nothing else moved.
        self::assertSame($originalEmail, $fresh->email);
        self::assertSame((string) $user->getKey(), (string) $fresh->getKey());
        self::assertNull($fresh->two_factor_confirmed_at);
        self::assertTrue(password_verify('password', (string) $fresh->password) === false
            || ! password_verify('not-a-password-change', (string) $fresh->password));
    }

    #[Test]
    public function a_role_change_cannot_smuggle_in_owner(): void
    {
        [$customer, $owner] = $this->account();

        $colleague = User::factory()->create();
        $membership = CustomerMember::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $colleague->id,
            'role' => CustomerRole::Member,
            'accepted_at' => now(),
        ]);

        /*
         * `owner` is not in the assignable set, and the reason is structural:
         * an account has exactly one owner, so granting the role would make
         * two and then none. The validator refuses it rather than the action
         * having to unpick it.
         */
        $this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->patchJson('/api/v1/team/members/'.$membership->getKey(), [
                'role' => 'owner',
            ])->assertStatus(422);

        self::assertSame(CustomerRole::Member, $membership->fresh()?->role);

        // A made-up role is refused the same way, rather than stored as text.
        $this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->patchJson('/api/v1/team/members/'.$membership->getKey(), [
                'role' => 'superuser',
            ])->assertStatus(422);

        self::assertSame(CustomerRole::Member, $membership->fresh()?->role);
    }

    #[Test]
    public function a_role_change_cannot_name_a_different_account(): void
    {
        [$customer, $owner] = $this->account();
        [$other] = $this->account();

        $colleague = User::factory()->create();
        $membership = CustomerMember::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $colleague->id,
            'role' => CustomerRole::Member,
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->patchJson('/api/v1/team/members/'.$membership->getKey(), [
                'role' => 'technical',
                // Ignored: the account is the one the middleware resolved.
                'customer_id' => (string) $other->getKey(),
            ])->assertOk();

        $fresh = $membership->fresh();
        self::assertNotNull($fresh);
        self::assertSame(CustomerRole::Technical, $fresh->role);
        self::assertSame((string) $customer->getKey(), (string) $fresh->customer_id);
    }

    #[Test]
    public function a_new_ticket_cannot_choose_its_own_status_or_its_own_handler(): void
    {
        [$customer, $user] = $this->account();

        $operator = User::factory()->create(['name' => 'Staff Member']);

        $this->actingAs($user)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'A question about a server',
                'body' => 'The machine is not answering on port 22.',
                'category' => 'technical',
                'priority' => 'normal',

                // A ticket that opened itself resolved would never be read; one
                // that assigned itself would name a member of staff the
                // customer has no business choosing.
                'status' => 'resolved',
                'assigned_to_user_id' => (string) $operator->getKey(),
                'customer_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
                'reference' => 'LYN-CHOSEN-BY-ME',
                'resolved_at' => now()->toIso8601String(),
            ])->assertCreated();

        $ticket = SupportTicket::query()->latest('created_at')->first();
        self::assertNotNull($ticket);

        self::assertSame((string) $customer->getKey(), (string) $ticket->customer_id);
        self::assertNotSame(TicketStatus::Resolved, $ticket->status);
        self::assertNull($ticket->assigned_to_user_id);
        self::assertNull($ticket->resolved_at);
        self::assertNotSame('LYN-CHOSEN-BY-ME', $ticket->reference);
    }

    #[Test]
    public function a_ticket_cannot_be_opened_at_a_priority_the_customer_may_not_choose(): void
    {
        [$customer, $user] = $this->account();

        /*
         * Urgency is a promise about response time. A customer who could open
         * every ticket at the top priority would make the queue meaningless,
         * so the selectable set is narrower than the enum.
         */
        $operatorOnly = array_values(array_diff(
            array_map(static fn (TicketPriority $p): string => $p->value, TicketPriority::cases()),
            TicketPriority::customerSelectableValues(),
        ));

        self::assertNotSame([], $operatorOnly, 'Every priority is customer-selectable; this test proves nothing.');

        $this->actingAs($user)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'Everything is on fire',
                'body' => 'Please look at this immediately, it is very important.',
                'category' => 'technical',
                'priority' => $operatorOnly[0],
            ])->assertStatus(422);
    }

    #[Test]
    public function a_token_cannot_be_minted_with_abilities_or_for_somebody_else(): void
    {
        [$customer, $user] = $this->account();

        $victim = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders(['X-Lynomia-Customer' => (string) $customer->getKey()])
            ->postJson('/api/v1/me/api-tokens', [
                'name' => 'ci',
                'current_password' => 'password',

                /*
                 * Nothing in the application checks abilities, so the request
                 * refuses to accept them rather than storing a scope that
                 * would read as a restriction and enforce nothing.
                 */
                'abilities' => ['*', 'admin'],
                'tokenable_id' => (string) $victim->getKey(),
                'customer_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
                'rate_limit_per_minute' => 100,
            ]);

        $response->assertCreated();

        $token = PersonalAccessToken::query()
            ->latest('created_at')
            ->first();

        self::assertNotNull($token);

        // Minted for the caller, in the caller's account, with the wildcard
        // ability the platform actually issues.
        self::assertSame((string) $user->getKey(), (string) $token->tokenable_id);
        self::assertSame((string) $customer->getKey(), (string) $token->customer_id);
        self::assertSame(['*'], $token->abilities);

        // The one restriction that is enforced was honoured.
        self::assertSame(100, $token->rate_limit_per_minute);
    }
}
