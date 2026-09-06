<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Lynomia\Modules\Identity\Application\Actions\ManageTwoFactor;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\LoginActivity;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('login');
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ], $overrides));
    }

    #[Test]
    public function a_correct_password_signs_the_user_in(): void
    {
        $user = $this->user();

        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_unknown_address_and_a_wrong_password_are_indistinguishable(): void
    {
        $this->user();

        $wrongPassword = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => 'not-the-password',
        ]);

        RateLimiter::clear('login');

        $unknownAddress = $this->postJson(route('api.v1.login'), [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ]);

        // Any difference here — status, code, or wording — turns the login form
        // into an account enumeration oracle.
        $this->assertSame($wrongPassword->status(), $unknownAddress->status());
        $this->assertSame(
            $wrongPassword->json('error.code'),
            $unknownAddress->json('error.code'),
        );
        $this->assertSame(
            $wrongPassword->json('error.message'),
            $unknownAddress->json('error.message'),
        );
        $this->assertSame('auth.invalid_credentials', $wrongPassword->json('error.code'));
    }

    #[Test]
    public function repeated_failures_lock_the_account(): void
    {
        $this->user();

        for ($attempt = 1; $attempt < User::MAX_FAILED_LOGIN_ATTEMPTS; $attempt++) {
            $this->postJson(route('api.v1.login'), [
                'email' => 'amal@example.com',
                'password' => 'wrong',
            ])->assertStatus(422);

            RateLimiter::clear('login');
        }

        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => 'wrong',
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'auth.account_locked');
    }

    #[Test]
    public function a_locked_account_rejects_even_the_correct_password(): void
    {
        $this->user(['locked_until' => now()->addMinutes(10)]);

        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'auth.account_locked');

        $this->assertGuest();
    }

    #[Test]
    public function failures_are_recorded_even_for_an_address_with_no_account(): void
    {
        $this->postJson(route('api.v1.login'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        // The pattern of attempts against non-existent accounts is exactly what
        // reveals credential stuffing, so it must be recorded.
        $activity = LoginActivity::query()->sole();
        $this->assertSame(LoginOutcome::FailedCredentials, $activity->outcome);
        $this->assertSame('nobody@example.com', $activity->email_attempted);
        $this->assertNull($activity->user_id);
    }

    #[Test]
    public function the_attempted_password_is_never_recorded(): void
    {
        $this->postJson(route('api.v1.login'), [
            'email' => 'nobody@example.com',
            'password' => 'a-very-distinctive-secret',
        ])->assertStatus(422);

        $raw = json_encode(LoginActivity::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('a-very-distinctive-secret', $raw);
    }

    #[Test]
    public function the_rate_limiter_stops_sustained_guessing(): void
    {
        $this->user();

        $attempts = (int) config('security.rate_limits.login.attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson(route('api.v1.login'), [
                'email' => 'amal@example.com',
                'password' => "guess-{$i}",
            ]);
        }

        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => 'guess-again',
        ])->assertStatus(429);
    }

    #[Test]
    public function throttling_one_address_does_not_lock_out_a_different_customer(): void
    {
        $this->user();
        $this->user(['email' => 'other@example.com']);

        $attempts = (int) config('security.rate_limits.login.attempts');

        for ($i = 0; $i <= $attempts; $i++) {
            $this->postJson(route('api.v1.login'), [
                'email' => 'amal@example.com',
                'password' => "guess-{$i}",
            ]);
        }

        // Keyed on address *and* source IP, so hammering one account must not
        // deny service to another from the same client.
        $this->postJson(route('api.v1.login'), [
            'email' => 'other@example.com',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function a_password_alone_never_yields_a_session_when_two_factor_is_enabled(): void
    {
        $this->user(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);

        $response = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'auth.two_factor_required');
        $this->assertNotEmpty($response->json('error.details.challenge_token'));

        // Not even briefly authenticated.
        $this->assertGuest();
    }

    #[Test]
    public function a_valid_totp_code_completes_the_sign_in(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->user(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);

        $challenge = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->json('error.details.challenge_token');

        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $challenge,
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_challenge_token_cannot_be_replayed(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->user(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);

        $challenge = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->json('error.details.challenge_token');

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $challenge,
            'code' => $code,
        ])->assertOk();

        $this->postJson(route('api.v1.logout'))->assertNoContent();

        // The same captured token must not work a second time.
        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $challenge,
            'code' => $code,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_wrong_second_factor_counts_towards_lockout(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->user(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);

        $challenge = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->json('error.details.challenge_token');

        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $challenge,
            'code' => '000000',
        ])->assertStatus(422);

        // Without this, the second factor is brute-forceable once the password
        // is known.
        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertGuest();
    }

    #[Test]
    public function a_recovery_code_works_once_and_only_once(): void
    {
        $user = $this->user();

        $twoFactor = app(ManageTwoFactor::class);
        $enrolment = $twoFactor->beginEnrolment($user);
        $codes = $twoFactor->confirmEnrolment(
            $user->fresh(),
            app(Google2FA::class)->getCurrentOtp($enrolment['secret']),
        );

        $this->assertIsArray($codes);
        $recoveryCode = $codes[0];

        $challenge = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->json('error.details.challenge_token');

        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $challenge,
            'code' => $recoveryCode,
        ])->assertOk();

        $this->postJson(route('api.v1.logout'))->assertNoContent();

        $secondChallenge = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->json('error.details.challenge_token');

        // Single use: a code captured in transit must not be replayable.
        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => $secondChallenge,
            'code' => $recoveryCode,
        ])->assertStatus(422);
    }

    #[Test]
    public function recovery_codes_are_stored_hashed(): void
    {
        $user = $this->user();

        $twoFactor = app(ManageTwoFactor::class);
        $enrolment = $twoFactor->beginEnrolment($user);
        $codes = $twoFactor->confirmEnrolment(
            $user->fresh(),
            app(Google2FA::class)->getCurrentOtp($enrolment['secret']),
        );

        $stored = $user->fresh()->two_factor_recovery_codes ?? [];

        // A database dump must not hand an attacker a working bypass.
        $this->assertNotContains($codes[0], $stored);
        $this->assertTrue(Hash::check($codes[0], $stored[0]));
    }

    #[Test]
    public function logging_out_destroys_the_session(): void
    {
        $user = $this->user();

        // Sign in for real rather than with actingAs(), so that the session and
        // the guard caches this exercises are the ones a browser produces.
        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->getJson(route('api.v1.me'))->assertOk();

        $this->postJson(route('api.v1.logout'))->assertNoContent();

        $this->getJson(route('api.v1.me'))->assertStatus(401);

        $this->assertDatabaseHas('login_activities', [
            'user_id' => $user->id,
            'outcome' => LoginOutcome::LoggedOut->value,
        ]);
    }
}
