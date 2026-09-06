<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hashing is expensive; every factory user that does not override the
     * password shares one pre-computed hash so that test suites building
     * hundreds of users stay fast.
     */
    protected static ?string $sharedPasswordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => Date::now(),
            'password' => self::$sharedPasswordHash ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'timezone' => 'UTC',
            'failed_login_attempts' => 0,
            'password_changed_at' => Date::now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
            'two_factor_confirmed_at' => Date::now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => [
            'locked_until' => Date::now()->addMinutes(User::LOCKOUT_MINUTES),
        ]);
    }
}
