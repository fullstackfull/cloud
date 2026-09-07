<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether an address holds an account here must not be answerable by anyone who
 * does not already know.
 *
 * It is a small-looking property with a large blast radius. Knowing which of a
 * leaked address list holds accounts at a hosting provider is what turns a
 * generic credential-stuffing run into a targeted one, and what makes a
 * phishing mail convincing: it names the right provider to the right person.
 *
 * The authentication path took this seriously already — one message, one status,
 * a dummy hash so an unknown address costs the same time as a wrong password.
 * Two other paths gave the answer away anyway, and both are closed here:
 *
 *   - the lockout replied 423 with a timestamp, and only a real account can be
 *     locked, so five requests classified any address with certainty;
 *   - registration replied 422 on a taken address, which is the same answer in
 *     one request.
 */
final class AccountEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_locked_account_is_indistinguishable_from_an_address_that_has_none(): void
    {
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $locked = $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => 'wrong-password',
        ]);

        $unknown = $this->postJson(route('api.v1.login'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertSame($unknown->status(), $locked->status());
        $this->assertSame(
            $unknown->json('error.code'),
            $locked->json('error.code'),
            'The lockout answers differently, so it says which addresses hold accounts.',
        );
        $this->assertNull($locked->json('error.details.locked_until'));
    }

    #[Test]
    public function the_owner_still_learns_that_their_account_is_locked(): void
    {
        // The point is not to withhold the lockout from the person it affects.
        // Someone who can present the password has already proved the account
        // exists, so telling them when it unlocks reveals nothing new — and it
        // is a far better answer than "wrong password" when the password was
        // right.
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'auth.account_locked');
    }

    #[Test]
    public function registration_does_not_confirm_whether_an_address_already_has_an_account(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'taken@example.com']);

        $payload = static fn (string $email): array => [
            'name' => 'Amal Al-Sabah',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'country' => 'KW',
            'accepts_terms' => true,
        ];

        $taken = $this->postJson(route('api.v1.register'), $payload('taken@example.com'));
        $free = $this->postJson(route('api.v1.register'), $payload('free@example.com'));

        $this->assertSame($free->status(), $taken->status());
        $this->assertSame($free->getContent(), $taken->getContent());
    }

    #[Test]
    public function the_password_reset_endpoint_answers_the_same_either_way(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $taken = $this->postJson(route('api.v1.password.forgot'), ['email' => 'taken@example.com']);
        $unknown = $this->postJson(route('api.v1.password.forgot'), ['email' => 'nobody@example.com']);

        $this->assertSame($unknown->status(), $taken->status());
        $this->assertSame($unknown->getContent(), $taken->getContent());
    }
}
