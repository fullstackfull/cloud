<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The password re-confirmation on two-factor disable and password change.
 *
 * This endpoint is the control that stops a hijacked session from removing the
 * second factor, which makes it the single most attractive password oracle in
 * the product: the attacker already holds the session, so there is no sign-in
 * form between them and an unlimited number of guesses. Before this was fixed,
 * two hundred wrong passwords left the account unlocked, the counter at zero
 * and the customer's history empty.
 *
 * Three properties, and the endpoint needs all three. Throttling alone leaves a
 * slow guesser unbounded. Lockout alone still lets an attacker consume the
 * budget at wire speed. Both without a history entry means the one attack shape
 * that leaves no other trace - no failed sign-in, no new session, no
 * notification - is invisible to the person it is aimed at.
 */
final class PasswordConfirmationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function guessing_the_password_on_two_factor_disable_is_throttled(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        $statuses = [];

        for ($i = 0; $i < 200; $i++) {
            $statuses[] = $this->deleteJson(route('api.v1.me.2fa.disable'), [
                'current_password' => 'guess-'.$i,
            ])->status();
        }

        $this->assertContains(429, $statuses, 'The endpoint accepted 200 guesses without throttling.');
    }

    #[Test]
    public function a_wrong_password_counts_towards_the_account_lockout(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        $this->deleteJson(route('api.v1.me.2fa.disable'), [
            'current_password' => 'not-the-password',
        ])->assertStatus(422);

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
    }

    #[Test]
    public function enough_wrong_passwords_lock_the_account_and_the_second_factor_survives(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->deleteJson(route('api.v1.me.2fa.disable'), [
                'current_password' => 'guess-'.$i,
            ]);
        }

        $fresh = $user->fresh();

        $this->assertNotNull($fresh->locked_until, 'The account never locked.');
        $this->assertTrue($fresh->hasTwoFactorEnabled());

        // And the correct password is refused while the lock stands, so the
        // lockout cannot be walked around by finally guessing right.
        $this->deleteJson(route('api.v1.me.2fa.disable'), [
            'current_password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function a_failed_confirmation_appears_in_the_customers_own_login_history(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        $this->deleteJson(route('api.v1.me.2fa.disable'), [
            'current_password' => 'not-the-password',
        ])->assertStatus(422);

        $activity = $user->loginActivities()->latest('created_at')->first();

        $this->assertNotNull($activity, 'Nothing was recorded, so the customer cannot see the attempt.');
        $this->assertSame(LoginOutcome::FailedPasswordConfirmation, $activity->outcome);

        // It is a distinct outcome rather than a failed sign-in: the customer
        // needs to tell "somebody tried to sign in as me" from "somebody
        // already inside my session tried to guess my password".
        $this->assertNotSame(LoginOutcome::FailedCredentials, $activity->outcome);

        // And it is visible through the endpoint the security page reads.
        $this->getJson(route('api.v1.me.login_activity'))
            ->assertOk()
            ->assertJsonFragment(['outcome' => LoginOutcome::FailedPasswordConfirmation->value]);
    }

    #[Test]
    public function the_password_change_endpoint_is_guarded_the_same_way(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'not-the-password',
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertStatus(422);

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertSame(
            LoginOutcome::FailedPasswordConfirmation,
            $user->loginActivities()->latest('created_at')->first()?->outcome,
        );
    }
}
