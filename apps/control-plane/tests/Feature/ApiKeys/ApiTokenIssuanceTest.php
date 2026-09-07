<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Lynomia\Modules\ApiKeys\Application\Actions\IssueApiToken;
use Lynomia\Modules\ApiKeys\Domain\Exceptions\RateLimitAboveCeilingException;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ApiKeys\Concerns\BuildsAccountMembers;
use Tests\TestCase;

/**
 * POST /api/v1/me/api-tokens.
 *
 * Minting a credential, so three things are being tested at once: that the
 * plaintext is returned once and is real, that the row it comes from is bound
 * to the account the caller is acting for and to nothing the caller sent, and
 * that the session alone was not enough to get it.
 */
final class ApiTokenIssuanceTest extends TestCase
{
    use BuildsAccountMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_returns_a_working_plaintext_token_exactly_once(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $response = $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'deployment pipeline',
                'current_password' => 'password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'deployment pipeline')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('meta.plaintext_shown_once', true);

        $plain = $response->json('data.token');
        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);

        /*
         * The credential works — and it, rather than the session actingAs()
         * left on the guard, is what authenticates: Sanctum's guard prefers a
         * session user, so without forgetting the guards this assertion would
         * pass for any string at all.
         */
        Auth::forgetGuards();

        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withToken($plain)
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        // And it is not recoverable: what was stored is a digest of the half
        // after the id, so no later read can reproduce the header value.
        $token = PersonalAccessToken::query()->findOrFail($response->json('data.id'));
        [$id, $secret] = explode('|', $plain, 2);
        $this->assertSame($token->id, $id);
        $this->assertSame(hash('sha256', $secret), $token->token);
        $this->assertStringNotContainsString($secret, json_encode($token->getAttributes(), JSON_THROW_ON_ERROR));

        // Listing it afterwards never repeats the plaintext.
        $this->actingAs($user->fresh())
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $token->id)
            ->assertJsonMissingPath('data.0.token');
    }

    /**
     * The 201 is the only response in the API whose body is a live bearer
     * credential, so it must never be cacheable. The guarantee comes from the
     * SecurityHeaders middleware rather than from this endpoint — which is
     * fine, and is exactly why it is pinned here: a change to that middleware
     * would otherwise turn one shared proxy into a second copy of every token
     * this platform issues, with nothing failing to say so.
     */
    #[Test]
    public function the_response_carrying_the_plaintext_is_never_cached(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $response = $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'ci',
                'current_password' => 'password',
            ])
            ->assertCreated();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function the_token_is_bound_to_the_acting_account_and_the_body_cannot_move_it(): void
    {
        $user = User::factory()->create();
        $acting = Customer::factory()->create();
        $other = Customer::factory()->create();
        $this->member($acting, $user);
        $this->member($other, $user);

        $response = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'ci',
                'current_password' => 'password',

                // A customer id in the body is a request to act on another
                // account. It must not be read at all — not even to be
                // rejected, which is why this succeeds and binds to the acting
                // account rather than 422ing.
                'customer_id' => $other->id,
                'tokenable_id' => User::factory()->create()->id,
            ])
            ->assertCreated();

        $token = PersonalAccessToken::query()->findOrFail($response->json('data.id'));

        $this->assertSame($acting->id, $token->customer_id);
        $this->assertSame($user->id, $token->tokenable_id);
    }

    /**
     * The 201 is served by a *different* resource class from the list and the
     * revocation response — IssuedApiTokenResource, which merges the shared
     * fields and then adds the plaintext. Nothing asserted what that merge
     * omits, so a field added to the issuing half would have reached customers
     * with every other test still green. `data.token` here is the plaintext by
     * design; the stored digest, the account id and the polymorphic wiring are
     * not.
     */
    #[Test]
    public function the_issuance_response_carries_the_plaintext_and_nothing_else_internal(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $response = $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'ci',
                'current_password' => 'password',
            ])
            ->assertCreated();

        $response->assertJsonMissingPath('data.customer_id');
        $response->assertJsonMissingPath('data.tokenable_id');
        $response->assertJsonMissingPath('data.tokenable_type');

        $token = PersonalAccessToken::query()->findOrFail($response->json('data.id'));
        $body = $response->getContent() ?: '';

        // The digest is the stored half of the credential and has no place in a
        // response, not even the one that legitimately carries the other half.
        $this->assertStringNotContainsString($token->token, $body);
        $this->assertStringNotContainsString($customer->id, $body);
        $this->assertStringNotContainsString($user->id, $body);
    }

    #[Test]
    public function the_optional_controls_are_stored_as_given(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $expiry = now()->addMonth()->startOfSecond();

        $response = $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'office automation',
                'current_password' => 'password',
                'expires_at' => $expiry->toIso8601String(),
                'allowed_ip_ranges' => ['198.51.100.0/24', '203.0.113.7', '198.51.100.0/24'],
                'rate_limit_per_minute' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('data.rate_limit_per_minute', 30)
            ->assertJsonPath('data.allowed_ip_ranges', ['198.51.100.0/24', '203.0.113.7']);

        $token = PersonalAccessToken::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['198.51.100.0/24', '203.0.113.7'], $token->allowed_ip_ranges);
        $this->assertSame(30, $token->rate_limit_per_minute);
        $this->assertTrue($expiry->equalTo($token->expires_at));
    }

    #[Test]
    public function issuing_a_token_requires_the_current_password(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'stolen session',
                'current_password' => 'not-the-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);

        $this->assertSame(0, PersonalAccessToken::query()->count());

        // A session with no password at all gets nothing either.
        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), ['name' => 'no password'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function a_wrong_password_counts_towards_the_lockout_and_is_recorded(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'guessing',
                'current_password' => 'wrong',
            ])
            ->assertStatus(422);

        // Guessing at this endpoint is otherwise the one attack that leaves no
        // trace: no failed sign-in, no new session row, no notification.
        $this->assertSame(1, $user->fresh()?->failed_login_attempts);
        $this->assertDatabaseHas('login_activities', [
            'user_id' => $user->id,
            'outcome' => 'failed_password_confirmation',
        ]);
    }

    #[Test]
    public function the_name_is_required_and_the_failure_names_the_field(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'current_password' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name']]]]);
    }

    #[Test]
    public function a_token_may_not_raise_its_own_rate_limit_above_the_tier_ceiling(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $ceiling = (int) config('security.rate_limits.api_token.attempts');

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'unthrottled',
                'current_password' => 'password',
                'rate_limit_per_minute' => $ceiling + 1_000_000,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['rate_limit_per_minute']]]]);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function the_ceiling_is_enforced_by_the_action_and_not_only_by_validation(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $ceiling = (int) config('security.rate_limits.api_token.attempts');

        // The form request would have caught this on the way in. The bound
        // lives in the action too, so a queue job or a future admin path that
        // mints a token cannot lift a customer's throttle by not going through
        // HTTP.
        $this->expectException(RateLimitAboveCeilingException::class);

        app(IssueApiToken::class)->execute(
            user: $user,
            customer: $customer,
            name: 'unthrottled',
            rateLimitPerMinute: $ceiling + 1,
        );
    }

    #[Test]
    public function a_malformed_allow_list_or_a_past_expiry_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'typo',
                'current_password' => 'password',
                // A range that IpUtils cannot evaluate would fail closed at
                // authentication time, which is a token that silently never
                // works.
                'allowed_ip_ranges' => ['198.51.100.0/33', 'office-nat'],
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['details' => ['fields' => ['allowed_ip_ranges.0', 'allowed_ip_ranges.1', 'expires_at']]],
            ]);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function a_member_without_apikey_manage_may_not_mint_one(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer, null, CustomerRole::Member);

        $this->actingAs($user)
            ->postJson(route('api.v1.me.api_tokens.store'), [
                'name' => 'not mine to make',
                'current_password' => 'password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }
}
