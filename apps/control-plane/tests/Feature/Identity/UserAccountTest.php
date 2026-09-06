<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function users_and_customers_use_ulid_primary_keys(): void
    {
        $user = User::factory()->create();

        // 26 characters, Crockford base32. Sequential integers would leak the
        // platform's customer count in every URL.
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $user->id);
    }

    #[Test]
    public function two_factor_secrets_are_encrypted_at_rest(): void
    {
        $user = User::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create();

        $stored = $this->getConnection()
            ->table('users')
            ->where('id', $user->id)
            ->value('two_factor_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $stored, 'The TOTP secret must not be readable in a database dump.');
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->two_factor_secret);
    }

    #[Test]
    public function sensitive_attributes_are_hidden_from_serialisation(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $serialised = $user->toArray();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $serialised);
        }
    }

    #[Test]
    public function an_account_locks_after_the_configured_number_of_failures(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt < User::MAX_FAILED_LOGIN_ATTEMPTS; $attempt++) {
            $this->assertFalse($user->registerFailedLogin(), "Attempt {$attempt} must not lock the account.");
            $this->assertFalse($user->isLocked());
        }

        $this->assertTrue($user->registerFailedLogin(), 'The final attempt must lock the account.');
        $this->assertTrue($user->isLocked());
    }

    #[Test]
    public function a_lock_expires_rather_than_being_permanent(): void
    {
        $user = User::factory()->locked()->create();
        $this->assertTrue($user->isLocked());

        // A permanent lock would let an attacker deny a legitimate user access
        // simply by guessing their password often enough.
        $this->travel(User::LOCKOUT_MINUTES + 1)->minutes();

        $this->assertFalse($user->fresh()->isLocked());
    }

    #[Test]
    public function a_successful_login_clears_the_failure_counter(): void
    {
        $user = User::factory()->create(['failed_login_attempts' => 3]);

        $user->registerSuccessfulLogin('203.0.113.10');

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertSame('203.0.113.10', $user->last_login_ip);
        $this->assertNotNull($user->last_login_at);
    }

    #[Test]
    public function a_user_can_belong_to_several_customer_accounts_with_different_roles(): void
    {
        $user = User::factory()->create();
        $own = Customer::factory()->create();
        $clients = Customer::factory()->organization()->create();

        $own->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => Date::now(),
        ]);
        $clients->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Member,
            'accepted_at' => Date::now(),
        ]);

        $user->load('memberships');

        $this->assertSame(CustomerRole::Owner, $user->roleWithin($own->id));
        $this->assertSame(CustomerRole::Member, $user->roleWithin($clients->id));
        $this->assertNull($user->roleWithin('01JQZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    #[Test]
    public function a_user_cannot_be_added_to_the_same_customer_twice(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner]);

        $this->expectException(UniqueConstraintViolationException::class);

        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Member]);
    }

    #[Test]
    public function customer_roles_grant_capabilities_by_permission_not_by_name(): void
    {
        $this->assertTrue(CustomerRole::Owner->can('customer.close'));
        $this->assertFalse(CustomerRole::Administrator->can('customer.close'));

        $this->assertTrue(CustomerRole::Billing->can('billing.pay'));
        $this->assertFalse(CustomerRole::Member->can('billing.pay'));

        // Every role can at least see the services it is responsible for.
        foreach (CustomerRole::cases() as $role) {
            $this->assertTrue($role->can('service.view'), "{$role->value} must be able to view services.");
        }
    }

    #[Test]
    public function a_suspended_customer_cannot_purchase(): void
    {
        $this->assertTrue(Customer::factory()->create()->canPurchase());
        $this->assertFalse(Customer::factory()->suspended()->create()->canPurchase());
        $this->assertFalse(CustomerStatus::Closed->canPurchase());
    }
}
