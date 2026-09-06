<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which customer account a request acts for.
 *
 * This is the whole of multi-tenancy in one decision. Get it wrong in either
 * direction and the platform is broken: too permissive and one customer reaches
 * another's invoices and servers, too eager and a request is silently attributed
 * to an account the caller did not mean, which on a billing API means the wrong
 * balance is charged.
 *
 * The rule is that the answer comes from the authenticated principal and never
 * from the request body. These tests exist to keep that true as endpoints are
 * added.
 */
final class ActingCustomerResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A probe carrying exactly the middleware a customer-scoped route
        // carries, so the resolution is exercised through the real stack rather
        // than by calling the middleware directly.
        Route::middleware(['api', 'auth:sanctum', 'customer'])
            ->get('/api/test/acting-customer', static fn (): array => [
                'customer_id' => app(ActingCustomer::class)->id(),
            ]);
    }

    private function member(Customer $customer, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    #[Test]
    public function a_login_with_one_account_needs_to_say_nothing(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        $this->actingAs($user)
            ->getJson('/api/test/acting-customer')
            ->assertOk()
            ->assertJsonPath('customer_id', $customer->id);
    }

    #[Test]
    public function a_login_with_two_accounts_must_name_one(): void
    {
        $user = User::factory()->create();
        $this->member(Customer::factory()->create(), $user);
        $this->member(Customer::factory()->create(), $user);

        // Refused rather than guessed. Picking the first would be a coin toss
        // over which account gets billed.
        $this->actingAs($user)
            ->getJson('/api/test/acting-customer')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.account_required');
    }

    #[Test]
    public function naming_an_account_the_login_belongs_to_selects_it(): void
    {
        $user = User::factory()->create();
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $this->member($first, $user);
        $this->member($second, $user);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $second->id)
            ->getJson('/api/test/acting-customer')
            ->assertOk()
            ->assertJsonPath('customer_id', $second->id);
    }

    #[Test]
    public function naming_an_account_the_login_does_not_belong_to_is_refused(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $user = $this->member($mine);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $theirs->id)
            ->getJson('/api/test/acting-customer')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.account_unavailable');
    }

    #[Test]
    public function an_account_that_does_not_exist_is_refused_identically(): void
    {
        $user = $this->member(Customer::factory()->create());

        // Byte for byte the same as a real account the caller is not a member
        // of. Any difference would let a caller sort real customer ids from
        // invented ones.
        $real = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', Customer::factory()->create()->id)
            ->getJson('/api/test/acting-customer');

        $invented = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->getJson('/api/test/acting-customer');

        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_invitation_that_was_never_accepted_selects_nothing(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => null,
        ]);

        $this->actingAs($user)
            ->getJson('/api/test/acting-customer')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.no_account');
    }

    #[Test]
    public function a_token_bound_to_an_account_decides_and_a_header_cannot_widen_it(): void
    {
        $user = User::factory()->create();
        $bound = Customer::factory()->create();
        $other = Customer::factory()->create();
        $this->member($bound, $user);
        $this->member($other, $user);

        $token = $user->createToken('scoped');
        $token->accessToken->forceFill(['customer_id' => $bound->id])->save();

        // The token decides even though the login is a member of both.
        $this->withToken($token->plainTextToken)
            ->getJson('/api/test/acting-customer')
            ->assertOk()
            ->assertJsonPath('customer_id', $bound->id);

        // And a header naming the other account is refused, not ignored: acting
        // on the token's account anyway would answer a question nobody asked.
        $this->withToken($token->plainTextToken)
            ->withHeader('X-Lynomia-Customer', $other->id)
            ->getJson('/api/test/acting-customer')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.outside_token_scope');
    }

    #[Test]
    public function a_scoped_token_stops_working_when_the_membership_behind_it_ends(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $this->member($customer, $user);

        $token = $user->createToken('scoped');
        $token->accessToken->forceFill(['customer_id' => $customer->id])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/test/acting-customer')
            ->assertOk();

        // A token outlives the membership that justified it. Someone whose
        // access was removed must lose it everywhere at once, not only in the
        // browser.
        $customer->members()->where('user_id', $user->id)->delete();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/test/acting-customer')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.account_unavailable');
    }

    #[Test]
    public function a_malformed_header_is_treated_as_absent_rather_than_as_an_error(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->member($customer);

        // A proxy injecting a stray header must not be able to make every
        // request fail. The value is checked against membership either way, so
        // nothing is trusted by ignoring it.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', 'not-a-ulid')
            ->getJson('/api/test/acting-customer')
            ->assertOk()
            ->assertJsonPath('customer_id', $customer->id);
    }

    #[Test]
    public function the_acting_customer_does_not_leak_between_requests(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $firstUser = $this->member($first);
        $secondUser = $this->member($second);

        // Scoped, not singleton: under a worker that keeps the container alive
        // between requests, a plain singleton would serve the previous
        // customer's account to the next caller.
        $this->actingAs($firstUser)->getJson('/api/test/acting-customer')
            ->assertJsonPath('customer_id', $first->id);

        $this->actingAs($secondUser)->getJson('/api/test/acting-customer')
            ->assertJsonPath('customer_id', $second->id);
    }
}
