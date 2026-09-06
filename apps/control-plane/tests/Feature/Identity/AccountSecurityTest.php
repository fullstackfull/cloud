<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('login');
    }

    private function signedInUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ], $overrides));

        $this->postJson(route('api.v1.login'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        return $user;
    }

    // --- password change -----------------------------------------------------

    #[Test]
    public function changing_the_password_requires_the_current_one(): void
    {
        $this->signedInUser();

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'not-my-password',
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);
    }

    #[Test]
    public function changing_the_password_revokes_every_other_session_and_token(): void
    {
        $user = $this->signedInUser();

        // A second browser and an API token, as a real customer would have.
        DB::table('sessions')->insert([
            'id' => 'other-browser-session',
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Other browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
        $user->createToken('automation');

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        // A customer who changes their password because they suspect compromise
        // must not leave the attacker's session running.
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser-session']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('a-brand-new-secret-1', $user->fresh()->password));
    }

    #[Test]
    public function a_weak_new_password_is_rejected(): void
    {
        $this->signedInUser();

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    // --- profile -------------------------------------------------------------

    #[Test]
    public function the_email_address_cannot_be_changed_through_the_profile_endpoint(): void
    {
        $user = $this->signedInUser();

        $this->patchJson(route('api.v1.me.update'), [
            'name' => 'Amal A.',
            'email' => 'attacker@example.com',
        ])->assertOk();

        // Changing an address requires re-verification, so it must not ride
        // along with an ordinary profile update.
        $this->assertSame('amal@example.com', $user->fresh()->email);
        $this->assertSame('Amal A.', $user->fresh()->name);
    }

    #[Test]
    public function an_unsupported_locale_is_rejected(): void
    {
        $this->signedInUser();

        $this->patchJson(route('api.v1.me.update'), ['locale' => 'fr'])->assertStatus(422);
        $this->patchJson(route('api.v1.me.update'), ['locale' => 'ar'])->assertOk();
    }

    // --- sessions ------------------------------------------------------------

    #[Test]
    public function a_customer_sees_only_their_own_sessions(): void
    {
        $user = $this->signedInUser();
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            [
                'id' => 'someone-elses-session',
                'user_id' => $other->id,
                'ip_address' => '198.51.100.9',
                'user_agent' => 'Their browser',
                'payload' => base64_encode(serialize([])),
                'last_activity' => time(),
            ],
        ]);

        $response = $this->getJson(route('api.v1.me.sessions'))->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains(hash('sha256', 'someone-elses-session'), $ids);
    }

    #[Test]
    public function the_raw_session_identifier_is_never_disclosed(): void
    {
        $user = $this->signedInUser();

        DB::table('sessions')->insert([
            'id' => 'a-very-distinctive-session-id',
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Other browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $response = $this->getJson(route('api.v1.me.sessions'))->assertOk();

        // The raw id is a bearer credential; handing it to the client would let
        // a leaked response body be replayed as a session.
        $this->assertStringNotContainsString('a-very-distinctive-session-id', $response->getContent() ?: '');
    }

    #[Test]
    public function a_customer_cannot_revoke_someone_elses_session(): void
    {
        $this->signedInUser();
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'someone-elses-session',
            'user_id' => $other->id,
            'ip_address' => '198.51.100.9',
            'user_agent' => 'Their browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $this->deleteJson(route('api.v1.me.sessions.destroy', [
            'session' => hash('sha256', 'someone-elses-session'),
        ]))->assertStatus(404);

        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses-session']);
    }

    #[Test]
    public function revoking_other_sessions_keeps_the_current_one(): void
    {
        $user = $this->signedInUser();

        DB::table('sessions')->insert([
            'id' => 'other-browser-session',
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Other browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $this->deleteJson(route('api.v1.me.sessions.destroy_others'))->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser-session']);
        $this->getJson(route('api.v1.me'))->assertOk();
    }

    // --- two-factor management ----------------------------------------------

    #[Test]
    public function enabling_two_factor_requires_the_current_password(): void
    {
        $this->signedInUser();

        // A hijacked session must not be enough to change second-factor
        // settings.
        $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => 'wrong'])
            ->assertStatus(422);

        $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url']]);
    }

    #[Test]
    public function two_factor_is_not_active_until_a_code_is_confirmed(): void
    {
        $user = $this->signedInUser();

        $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])->assertOk();

        // A secret exists, but the account is not yet protected: confirming
        // first is what prevents locking someone out with a misconfigured app.
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function confirming_two_factor_returns_recovery_codes_once(): void
    {
        $user = $this->signedInUser();

        $secret = $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])
            ->json('data.secret');

        $response = $this->postJson(route('api.v1.me.2fa.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk();

        $codes = $response->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($response->json('meta.shown_once'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function disabling_two_factor_requires_the_current_password(): void
    {
        // Signed in via the session rather than the password flow, because an
        // account with a second factor cannot complete password-only sign-in
        // by design.
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        $this->deleteJson(route('api.v1.me.2fa.disable'), ['current_password' => 'wrong'])
            ->assertStatus(422);

        // A hijacked session alone must never be able to remove the second
        // factor.
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->deleteJson(route('api.v1.me.2fa.disable'), ['current_password' => self::PASSWORD])
            ->assertNoContent();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    // --- password reset ------------------------------------------------------

    #[Test]
    public function the_forgot_password_endpoint_never_reveals_whether_an_account_exists(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson(route('api.v1.password.forgot'), ['email' => 'known@example.com']);
        RateLimiter::clear('password-reset');
        $unknown = $this->postJson(route('api.v1.password.forgot'), ['email' => 'unknown@example.com']);

        $this->assertSame(202, $known->status());
        $this->assertSame(202, $unknown->status());
        $this->assertSame($known->json('data.message'), $unknown->json('data.message'));
    }

    #[Test]
    public function a_password_reset_clears_lockout_and_revokes_everything(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
            'locked_until' => now()->addMinutes(10),
            'failed_login_attempts' => 4,
        ]);
        $user->createToken('automation');
        DB::table('sessions')->insert([
            'id' => 'stale-session',
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Old browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $token = Password::createToken($user);

        $this->postJson(route('api.v1.password.reset'), [
            'token' => $token,
            'email' => 'amal@example.com',
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $user->refresh();

        // Control of the mailbox has been proved, so the lockout is lifted.
        $this->assertNull($user->locked_until);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertTrue(Hash::check('a-brand-new-secret-1', $user->password));

        // A reset is usually a response to suspected compromise.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-session']);
    }

    #[Test]
    public function a_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'amal@example.com']);
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => 'amal@example.com',
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ];

        $this->postJson(route('api.v1.password.reset'), $payload)->assertNoContent();
        RateLimiter::clear('password-reset');

        $this->postJson(route('api.v1.password.reset'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'password.reset_failed');
    }

    #[Test]
    public function a_reset_link_is_actually_sent_to_a_known_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson(route('api.v1.password.forgot'), ['email' => 'known@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
