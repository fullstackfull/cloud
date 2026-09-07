<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: PersonalAccessToken advertises a CIDR allow-list, but nothing
 * read allowed_ip_ranges, so a token restricted to an office range
 * authenticated from anywhere. The check belongs at the single point every
 * token-authenticated request passes through, not in each endpoint.
 */
final class TokenIpAllowListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  list<string>|null  $ranges
     */
    private function tokenFor(User $user, ?array $ranges): string
    {
        $plain = $user->createToken('automation')->plainTextToken;

        PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->update(['allowed_ip_ranges' => $ranges === null ? null : json_encode($ranges)]);

        return $plain;
    }

    #[Test]
    public function a_token_without_an_allow_list_authenticates_from_anywhere(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user, null);

        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson(route('api.v1.me'))
            ->assertOk();
    }

    #[Test]
    public function a_token_is_refused_from_an_address_outside_its_allow_list(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user, ['198.51.100.0/24']);

        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson(route('api.v1.me'))
            ->assertStatus(401);
    }

    #[Test]
    public function a_token_is_accepted_from_an_address_inside_its_allow_list(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user, ['198.51.100.0/24']);

        $this->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
            ->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson(route('api.v1.me'))
            ->assertOk();
    }

    #[Test]
    public function an_allow_list_that_cannot_be_evaluated_fails_closed(): void
    {
        $token = new PersonalAccessToken;
        $token->allowed_ip_ranges = ['198.51.100.0/24'];

        $this->assertFalse($token->allowsRequestFrom(null));
        $this->assertFalse($token->allowsRequestFrom(''));
    }
}
