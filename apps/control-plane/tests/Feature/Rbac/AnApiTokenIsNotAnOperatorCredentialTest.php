<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An API token is a credential for the customer API, never for /api/admin
 * (OB5-1, re-audit of round four).
 *
 * The operator surface is authenticated by the portal's Sanctum cookie session
 * (docs/api.md, "Authentication"); the only bearer tokens the platform issues
 * are the customer-surface ones IssueApiToken mints under
 * POST /api/v1/me/api-tokens — abilities `*`, bound to one customer account.
 * `auth:sanctum` on /api/admin is the same guard, so until the staff gate read
 * the request's access token, such a token was operator authority whenever
 * its holder held a staff role. Measured at a6b583b:
 *
 *  - an infrastructure admin who also owns a customer account minted a token
 *    for that account and read GET /api/admin/customers with it: 200;
 *  - a customer minted a token (403 on /api/admin), was promoted through
 *    POST /api/admin/operators, and the same token then read
 *    GET /api/admin/customers: 200.
 *
 * Each case is paired with its positive control: the same person's session
 * still opens /api/admin. In the first the same token still works on the
 * customer API; in the second, since B1 (re-audit after round five), the
 * invitation has deleted it, so it opens nothing anywhere.
 */
final class AnApiTokenIsNotAnOperatorCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function a_token_a_staff_member_mints_on_the_customer_surface_does_not_open_the_admin_surface(): void
    {
        [$operator, $customer] = $this->ownerOfAnAccount();
        $operator->syncRoles([Role::InfrastructureAdmin->value]);

        // Positive control: the session is operator authority.
        $this->actingAs($operator)->getJson('/api/admin/customers')->assertOk();

        $token = $this->mintThroughTheCustomerSurface($operator, $customer);

        foreach (['/api/admin/customers', '/api/admin/infrastructure/regions'] as $uri) {
            $this->withBearer($token)->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'auth.forbidden');
        }

        // Positive control: the token is still good for what it was minted for.
        $this->withBearer($token)->getJson('/api/v1/me/api-tokens')->assertOk();
    }

    #[Test]
    public function a_token_minted_as_a_customer_does_not_open_the_admin_surface_after_promotion(): void
    {
        [$user, $customer] = $this->ownerOfAnAccount();
        $user->syncRoles([Role::Customer->value]);

        $token = $this->mintThroughTheCustomerSurface($user, $customer);

        $this->withBearer($token)->getJson('/api/admin/customers')->assertForbidden();

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $this->forgetTheCaller();

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', [
                'email' => $user->email,
                'name' => $user->name,
                'roles' => [Role::InfrastructureAdmin->value],
            ])
            ->assertSuccessful();
        $this->assertTrue($user->fresh()?->hasRole(Role::InfrastructureAdmin->value), 'Precondition: promoted.');

        // Since B1 (re-audit after round five) the invitation deletes every
        // personal access token the promoted login held, so the token is not
        // refused by the staff gate any more: it authenticates nothing at all,
        // here or on the customer surface. The staff gate's refusal of a
        // token held by a staff member is the test above.
        $this->withBearer($token)->getJson('/api/admin/customers')->assertUnauthorized();
        $this->withBearer($token)->getJson('/api/v1/me')->assertUnauthorized();

        // Positive control: the promoted person's session opens it.
        $this->forgetTheCaller();
        $this->actingAs($user->fresh())->getJson('/api/admin/customers')->assertOk();
    }

    /**
     * A token that carries no customer binding — `createToken()`, as
     * `perf:token` issues one — is not an operator credential either: the
     * rule is "no bearer token", not "no bound bearer token".
     */
    #[Test]
    public function an_unbound_personal_access_token_of_a_staff_member_does_not_open_the_admin_surface(): void
    {
        $operator = User::factory()->create(['email_verified_at' => now()]);
        $operator->syncRoles([Role::SuperAdmin->value]);

        $token = $operator->createToken('unbound')->plainTextToken;

        $this->withBearer($token)->getJson('/api/admin/customers')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function ownerOfAnAccount(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'password']);
        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        return [$user, $customer];
    }

    private function mintThroughTheCustomerSurface(User $user, Customer $customer): string
    {
        $token = (string) $this->actingAs($user)
            ->postJson('/api/v1/me/api-tokens', ['name' => 'integration', 'current_password' => 'password'], ['X-Lynomia-Customer' => $customer->id])
            ->assertCreated()
            ->json('data.token');

        $this->assertNotSame('', $token);
        $this->forgetTheCaller();

        return $token;
    }

    /**
     * @return $this
     */
    private function withBearer(string $token): static
    {
        $this->forgetTheCaller();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function forgetTheCaller(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }
}
