<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Lynomia\Modules\ApiKeys\Application\Actions\IssueApiToken;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ApiKeys\Concerns\BuildsAccountMembers;
use Tests\TestCase;

/**
 * DELETE /api/v1/me/api-tokens/{token}.
 *
 * The endpoint that takes an id, so it is the one that has to 404 for
 * everything that is not the caller's own token on the acting account — 404 and
 * not 403, because ids here are ULIDs and a 403 confirms the row exists.
 *
 * It is also the endpoint that must not destroy evidence: the credential stops
 * working, the record of it does not.
 */
final class ApiTokenRevocationTest extends TestCase
{
    use BuildsAccountMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_revokes_by_recording_rather_than_deleting(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer, [
            'name' => 'ci',
            'last_used_at' => now()->subHour(),
            'last_used_ip' => '198.51.100.9',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]), [
                'reason' => 'Leaked in a public repository',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $token->id)
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revoked_reason', 'Leaked in a public repository')
            // The forensics survive the credential: this is the whole reason
            // the row is kept.
            ->assertJsonPath('data.last_used_ip', '198.51.100.9');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->id,
            'revoked_reason' => 'Leaked in a public repository',
        ]);
        $this->assertNotNull($token->fresh()?->revoked_at);

        // And it is still listed, marked revoked, rather than vanishing from
        // the customer's own record.
        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'revoked');
    }

    #[Test]
    public function a_revoked_token_stops_authenticating_immediately(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $issued = app(IssueApiToken::class)->execute($user, $customer, 'ci');
        $plain = $issued->plainTextToken;

        Auth::forgetGuards();
        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withToken($plain)
            ->getJson(route('api.v1.me'))
            ->assertOk();

        $this->actingAs($user->fresh())
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $issued->accessToken->getKey()]))
            ->assertOk();

        Auth::forgetGuards();
        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withToken($plain)
            ->getJson(route('api.v1.me'))
            ->assertStatus(401);
    }

    #[Test]
    public function another_customers_token_is_a_404_and_is_left_untouched(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $me = $this->member($mine);
        $them = $this->member($theirs);

        $theirToken = $this->tokenFor($them, $theirs);

        $this->actingAs($me)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $theirToken->id]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        // 404 rather than 403 on purpose: a 403 would confirm the id names a
        // real row, which is an enumeration oracle on ULIDs. So an invented id
        // has to answer identically.
        $invented = $this->actingAs($me)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => '01jzzzzzzzzzzzzzzzzzzzzzzz']))
            ->assertStatus(404);

        $this->assertSame('resource.not_found', $invented->json('error.code'));
        $this->assertNull($theirToken->fresh()?->revoked_at);
    }

    #[Test]
    public function a_colleagues_token_on_the_same_account_is_a_404(): void
    {
        $customer = Customer::factory()->create();
        $me = $this->member($customer);
        $colleague = $this->member($customer);

        $theirToken = $this->tokenFor($colleague, $customer);

        $this->actingAs($me)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $theirToken->id]))
            ->assertStatus(404);

        $this->assertNull($theirToken->fresh()?->revoked_at);
    }

    #[Test]
    public function my_own_token_on_my_other_account_is_a_404_while_acting_here(): void
    {
        $user = User::factory()->create();
        $acting = Customer::factory()->create();
        $other = Customer::factory()->create();
        $this->member($acting, $user);
        $this->member($other, $user);

        $onOther = $this->tokenFor($user, $other);

        // Same login, same person, different account. Acting for one account
        // must not reach the credentials of the other, or the binding a scoped
        // token exists for stops meaning anything.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $onOther->id]))
            ->assertStatus(404);

        $this->assertNull($onOther->fresh()?->revoked_at);

        // It is revocable from the account it belongs to.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $other->id)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $onOther->id]))
            ->assertOk();
    }

    #[Test]
    public function repeating_a_revocation_keeps_the_first_reason(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer);

        $first = $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]), [
                'reason' => 'Leaked in a public repository',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]), [
                'reason' => 'Tidying up',
            ])
            ->assertOk()
            // A retry after a timeout must not overwrite why the credential was
            // actually killed, nor move the moment it stopped working.
            ->assertJsonPath('data.revoked_reason', 'Leaked in a public repository')
            ->assertJsonPath('data.revoked_at', $first->json('data.revoked_at'));
    }

    #[Test]
    public function a_revocation_without_a_reason_still_records_who_did_it(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer);

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]))
            ->assertOk()
            ->assertJsonPath('data.revoked_reason', 'Revoked by user '.$user->id);
    }

    #[Test]
    public function an_over_long_reason_is_a_422_naming_the_field(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer);

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]), [
                'reason' => str_repeat('a', 129),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['reason']]]]);

        $this->assertNull($token->fresh()?->revoked_at);
    }

    #[Test]
    public function the_revocation_response_carries_nothing_internal(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer);

        $response = $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]))
            ->assertOk();

        $response->assertJsonMissingPath('data.token');
        $response->assertJsonMissingPath('data.customer_id');
        $response->assertJsonMissingPath('data.tokenable_id');
        $response->assertJsonMissingPath('data.tokenable_type');
        $this->assertStringNotContainsString($token->token, $response->getContent() ?: '');
    }

    /**
     * Regression: destroy required `apikey.manage`, which made revocation
     * harder than issuance and produced a credential nobody could switch off.
     *
     * An owner mints a token and is later demoted to member — which is exactly
     * when somebody wants that key dead. They were refused with a 403, and no
     * colleague could do it for them either: the query hangs off the token
     * owner's own login, so an administrator's list does not contain the row.
     * The token stayed live until it expired, or forever if it had no expiry.
     */
    #[Test]
    public function a_demoted_member_can_still_revoke_the_token_they_minted(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer, null, CustomerRole::Owner);

        $issued = $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'ci',
                'current_password' => 'password',
            ])
            ->assertCreated();

        $id = (string) $issued->json('data.id');

        $customer->members()
            ->where('user_id', $user->id)
            ->update(['role' => CustomerRole::Member->value]);

        $this->actingAs($user->fresh())
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $id]))
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertNotNull(PersonalAccessToken::query()->findOrFail($id)->revoked_at);
    }

    #[Test]
    public function a_member_may_not_mint_a_token_even_though_they_may_revoke_one(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer, null, CustomerRole::Member);

        // Revoking is the safe direction and stays open; minting does not.
        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'not mine to make',
                'current_password' => 'password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function a_member_still_cannot_reach_a_colleagues_token(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer, null, CustomerRole::Member);
        $colleague = $this->member($customer, null, CustomerRole::Owner);

        $theirs = $this->tokenFor($colleague, $customer);

        // Dropping the role check widened nothing: the rows reachable here were
        // always the caller's own, and an invented id still answers identically.
        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $theirs->id]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => '01jzzzzzzzzzzzzzzzzzzzzzzz']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->assertNull($theirs->fresh()?->revoked_at);
    }

    /**
     * Regression: the reason was cut with `substr()`, which counts bytes, while
     * validation bounded it at 128 *characters*. A 43-character Chinese reason
     * is 129 bytes, so the cut landed mid-character, Postgres refused the
     * resulting string as invalid UTF-8, and the request died with a 500 — with
     * the token still live. Somebody whose key had just leaked, writing the
     * reason in their own language, got a server error instead of a revocation.
     */
    #[Test]
    public function a_reason_in_a_multibyte_script_still_revokes_the_token(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        foreach ([str_repeat('泄', 43), str_repeat('漏', 128), str_repeat('🔑', 40)] as $reason) {
            $token = $this->tokenFor($user, $customer);

            $response = $this->actingAs($user)
                ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $token->id]), [
                    'reason' => $reason,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', 'revoked');

            $stored = (string) $response->json('data.revoked_reason');

            $this->assertNotSame('', $stored);
            $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'the recorded reason must be valid UTF-8');
            // What was kept is a prefix of what was sent, not a mangled tail.
            $this->assertStringStartsWith($stored, $reason);
            $this->assertNotNull($token->fresh()?->revoked_at, 'the credential must actually be revoked');
        }
    }

    #[Test]
    public function revocation_does_not_reach_a_token_that_was_never_bound_to_an_account(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        // Tokens minted before this surface existed, or by another mechanism,
        // carry no customer binding. They are not this account's to manage, and
        // they are not visible here either.
        $unbound = new PersonalAccessToken;
        $unbound->forceFill([
            'tokenable_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
            'name' => 'legacy',
            'token' => hash('sha256', 'legacy-plaintext'),
            'abilities' => ['*'],
        ])->save();

        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->deleteJson(route('api.v1.me.api_tokens.destroy', ['token' => $unbound->id]))
            ->assertStatus(404);
    }
}
