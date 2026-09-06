<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amal Al-Sabah',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ], $overrides);
    }

    #[Test]
    public function registration_creates_a_user_and_the_customer_account_they_own(): void
    {
        Event::fake([Registered::class]);

        $response = $this->postJson(route('api.v1.register'), $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'amal@example.com')
            ->assertJsonPath('data.customer.type', CustomerType::Individual->value)
            ->assertJsonPath('meta.email_verification_required', true);

        $user = User::query()->where('email', 'amal@example.com')->sole();
        $customer = Customer::query()->sole();

        // A user without a customer cannot be billed or own a service, so both
        // must exist after any successful registration.
        $this->assertTrue($user->hasRole(Role::Customer->value));
        $this->assertSame(CustomerRole::Owner, $customer->members()->sole()->role);
        $this->assertSame($user->id, $customer->members()->sole()->user_id);

        Event::assertDispatched(Registered::class);
    }

    #[Test]
    public function registration_does_not_sign_the_user_in(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload())->assertCreated();

        // Verification comes first: an address typo must not leave an
        // unreachable account holding an active session.
        $this->assertGuest();
    }

    #[Test]
    public function the_password_is_never_returned_or_stored_in_clear(): void
    {
        $response = $this->postJson(route('api.v1.register'), $this->validPayload());

        $response->assertCreated();
        $this->assertStringNotContainsString('correct-horse-9', $response->getContent() ?: '');

        $stored = $this->getConnection()->table('users')->where('email', 'amal@example.com')->value('password');
        $this->assertNotSame('correct-horse-9', $stored);
        $this->assertStringStartsWith('$', (string) $stored);
    }

    #[Test]
    public function an_organization_registration_records_the_legal_name(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload([
            'account_type' => CustomerType::Organization->value,
            'company_name' => 'Premier Care W.L.L.',
        ]))->assertCreated();

        $customer = Customer::query()->sole();

        $this->assertSame(CustomerType::Organization, $customer->type);
        $this->assertSame('Premier Care W.L.L.', $customer->display_name);
        $this->assertSame('Premier Care W.L.L.', $customer->legal_name);
    }

    #[Test]
    public function an_organization_registration_requires_a_company_name(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload([
            'account_type' => CustomerType::Organization->value,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['company_name']]]]);
    }

    #[Test]
    public function a_duplicate_address_is_rejected(): void
    {
        User::factory()->create(['email' => 'amal@example.com']);

        $this->postJson(route('api.v1.register'), $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');
    }

    #[Test]
    public function addresses_are_normalised_so_case_cannot_create_a_duplicate(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload())->assertCreated();

        $this->postJson(route('api.v1.register'), $this->validPayload([
            'email' => 'AMAL@Example.COM',
        ]))->assertStatus(422);
    }

    #[Test]
    public function terms_must_be_accepted_explicitly(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload(['accepts_terms' => false]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['accepts_terms']]]]);
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        $this->postJson(route('api.v1.register'), $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function a_failed_registration_leaves_no_partial_account(): void
    {
        // Deliberately invalid: the customer row must not survive a rolled-back
        // registration.
        $this->postJson(route('api.v1.register'), $this->validPayload(['email' => 'not-an-address']))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_members', 0);
    }

    #[Test]
    public function every_response_carries_a_correlation_id(): void
    {
        $response = $this->postJson(route('api.v1.register'), $this->validPayload());

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
