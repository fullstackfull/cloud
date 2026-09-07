<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ApiKeys\Concerns\BuildsAccountMembers;
use Tests\TestCase;

/**
 * GET /api/v1/me/api-tokens.
 *
 * The listing is scoped twice — to the login and to the account it is acting
 * for — and both halves are load-bearing. A list that showed a colleague's
 * tokens would let one member revoke another's automation; a list that showed
 * the caller's tokens for their *other* account would hand an operator working
 * on account A a credential that acts on account B.
 */
final class ApiTokenListingTest extends TestCase
{
    use BuildsAccountMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_lists_the_callers_tokens_for_the_acting_account_newest_first(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $older = $this->tokenFor($user, $customer, ['name' => 'older', 'created_at' => now()->subDay()]);
        $newer = $this->tokenFor($user, $customer, ['name' => 'newer']);

        $response = $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.status', 'active');

        $this->assertSame(2, $response->json('meta.total'));
    }

    #[Test]
    public function it_never_shows_a_colleagues_token_on_the_same_account(): void
    {
        $customer = Customer::factory()->create();
        $mine = $this->member($customer);
        $theirs = $this->member($customer);

        $ours = $this->tokenFor($mine, $customer, ['name' => 'mine']);
        $this->tokenFor($theirs, $customer, ['name' => 'theirs']);

        $this->actingAs($mine)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ours->id);
    }

    #[Test]
    public function a_user_who_administers_two_accounts_sees_only_the_acting_ones_tokens(): void
    {
        $user = User::factory()->create();
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $this->member($first, $user);
        $this->member($second, $user);

        $onFirst = $this->tokenFor($user, $first, ['name' => 'first']);
        $onSecond = $this->tokenFor($user, $second, ['name' => 'second']);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $first->id)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $onFirst->id);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $second->id)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $onSecond->id);
    }

    #[Test]
    public function the_listing_carries_nothing_internal(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);
        $token = $this->tokenFor($user, $customer);

        $response = $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk();

        // Named one by one rather than asserted as a shape: a resource that
        // grows a field is exactly how the digest gets published.
        $response->assertJsonMissingPath('data.0.token');
        $response->assertJsonMissingPath('data.0.customer_id');
        $response->assertJsonMissingPath('data.0.tokenable_id');
        $response->assertJsonMissingPath('data.0.tokenable_type');

        // And the digest itself is nowhere in the body under any key.
        $this->assertStringNotContainsString($token->token, $response->getContent() ?: '');
        $this->assertStringNotContainsString($customer->id, $response->getContent() ?: '');
    }

    #[Test]
    public function the_page_size_is_bounded_however_much_is_asked_for(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        foreach (range(1, 5) as $index) {
            $this->tokenFor($user, $customer, ['name' => 'token-'.$index]);
        }

        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index', ['per_page' => 100000]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonPath('meta.total', 5);

        // A page of two is honoured, so the clamp is a ceiling rather than a
        // fixed size.
        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3);
    }

    #[Test]
    public function the_status_filter_agrees_with_the_status_it_prints(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $active = $this->tokenFor($user, $customer, ['name' => 'active']);
        $expired = $this->tokenFor($user, $customer, ['name' => 'expired', 'expires_at' => now()->subDay()]);
        $revoked = $this->tokenFor($user, $customer, ['name' => 'revoked']);
        $revoked->revoke('leaked');

        foreach ([['active', $active], ['expired', $expired], ['revoked', $revoked]] as [$status, $expected]) {
            $this->actingAs($user)
                ->getJson(route('api.v1.me.api_tokens.index', ['status' => $status]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expected->id)
                ->assertJsonPath('data.0.status', $status);
        }
    }

    #[Test]
    public function an_unknown_filter_value_is_a_422_naming_the_field(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index', ['status' => 'whatever', 'per_page' => 'banana']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status', 'per_page']]]]);
    }

    /**
     * Regression: index required `apikey.manage`, so a member could not see
     * their own tokens — including one they minted while they were an owner.
     * You cannot revoke what you cannot find the id of, and the list is already
     * narrowed to the caller's own rows, so the role check protected nothing
     * here and hid a live credential from the only person able to kill it.
     */
    #[Test]
    public function a_member_without_apikey_manage_still_sees_their_own_tokens(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer, null, CustomerRole::Member);
        $mine = $this->tokenFor($user, $customer);

        // And still only their own: the role changed, the scoping did not.
        $colleague = $this->member($customer, null, CustomerRole::Owner);
        $this->tokenFor($colleague, $customer, ['name' => 'not mine']);

        $this->actingAs($user)
            ->getJson(route('api.v1.me.api_tokens.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }
}
