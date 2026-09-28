<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A reset link issued before a login's address was rewritten to its one
 * spelling still resets the password afterwards (B9-2, re-audit after round
 * eight).
 *
 * Measured at 6c234dc: 2026_05_04_000120 rewrote users.email and left
 * password_reset_tokens.email as it was. The broker finds a token by the
 * login's address, so a token issued to `Ärger@…` was not found once the
 * login read `ärger@…`: the reset answered 422 `password.reset_failed`.
 * 2026_05_05_000102 rewrites the token rows the same way.
 *
 * And the spelling itself changed in round nine (a domain's final root label
 * is dropped): 2026_05_05_000101 applies the one spelling again to users and
 * team invitations, refusing a collision exactly as 000120 and 000121 do.
 */
final class AResetTokenOutlivesTheSpellingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string NEW_PASSWORD = 'Brand-New-Password-88!';

    #[Test]
    public function a_token_issued_before_the_rewrite_resets_the_password_after_it(): void
    {
        Notification::fake();
        $login = User::factory()->create(['email_verified_at' => now()]);
        DB::table('users')->where('id', $login->id)->update(['email' => 'Ärger@lynomia.test']);
        $token = Password::broker()->createToken($login->fresh() ?? $login);
        $this->assertSame(['Ärger@lynomia.test'], DB::table('password_reset_tokens')->pluck('email')->all());

        $this->migration('2026_05_04_000120_store_every_login_address_in_its_one_spelling')->up();
        $this->migration('2026_05_05_000102_store_every_reset_token_address_in_its_one_spelling')->up();

        $this->assertSame(['ärger@lynomia.test'], DB::table('password_reset_tokens')->pluck('email')->all());

        $this->postJson('/api/v1/password/reset', [
            'email' => 'Ärger@lynomia.test', 'token' => $token,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $login->fresh()?->password));
    }

    /**
     * Two token rows that are one address once rewritten: the most recently
     * issued one is kept, and it is the one that works.
     */
    #[Test]
    public function of_two_tokens_for_one_address_the_most_recent_is_kept(): void
    {
        Notification::fake();
        $login = User::factory()->create(['email' => 'ärger@lynomia.test', 'email_verified_at' => now()]);
        DB::table('password_reset_tokens')->insert([
            ['email' => 'Ärger@lynomia.test', 'token' => Hash::make('older-token'), 'created_at' => now()->subMinutes(10)],
            ['email' => "A\u{0308}RGER@lynomia.test", 'token' => Hash::make('newer-token'), 'created_at' => now()->subMinute()],
            ['email' => 'other@lynomia.test', 'token' => Hash::make('other-token'), 'created_at' => now()->subMinutes(30)],
        ]);

        $this->migration('2026_05_05_000102_store_every_reset_token_address_in_its_one_spelling')->up();

        $this->assertSame(['other@lynomia.test', 'ärger@lynomia.test'], DB::table('password_reset_tokens')->orderBy('email')->pluck('email')->all());
        $this->assertTrue(Password::broker()->tokenExists($login, 'newer-token'));
        $this->assertFalse(Password::broker()->tokenExists($login, 'older-token'));
    }

    /**
     * A verification link carries sha1 of the address it was sent to, and
     * the rewrite changes the address: such a link is refused afterwards, as
     * the migration's docblock says, and a new one works.
     */
    #[Test]
    public function a_verification_link_for_a_rewritten_address_is_refused_and_a_new_one_works(): void
    {
        $login = User::factory()->create(['email_verified_at' => null]);
        DB::table('users')->where('id', $login->id)->update(['email' => 'Ärger@lynomia.test']);
        $old = URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), ['id' => $login->id, 'hash' => sha1('Ärger@lynomia.test')]);

        $this->migration('2026_05_04_000120_store_every_login_address_in_its_one_spelling')->up();

        $this->getJson($old)->assertForbidden()->assertJsonPath('error.code', 'verification.invalid_link');

        $new = URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), ['id' => $login->id, 'hash' => sha1('ärger@lynomia.test')]);
        $this->getJson($new)->assertOk()->assertJsonPath('data.verified', true);
    }

    /**
     * A login or an offer stored with its domain's root label — as the
     * operator and team invitations did before round nine — is rewritten
     * without it.
     */
    #[Test]
    public function an_address_stored_with_a_root_label_is_rewritten_without_it(): void
    {
        $login = User::factory()->create();
        DB::table('users')->where('id', $login->id)->update(['email' => 'ops@example.com.']);
        $offer = CustomerInvitation::factory()->create();
        DB::table('customer_invitations')->where('id', $offer->id)->update(['email' => 'mate@example.com.']);

        $this->migration('2026_05_05_000101_store_every_address_again_now_a_root_label_is_dropped')->up();

        $this->assertSame('ops@example.com', DB::table('users')->where('id', $login->id)->value('email'));
        $this->assertSame('mate@example.com', DB::table('customer_invitations')->where('id', $offer->id)->value('email'));
    }

    /**
     * The second login the defect made, beside the first: not merged, named.
     */
    #[Test]
    public function a_second_login_made_under_the_root_label_is_named_and_nothing_changes(): void
    {
        $first = User::factory()->create(['email' => 'ops@example.com']);
        $second = User::factory()->create();
        DB::table('users')->where('id', $second->id)->update(['email' => 'ops@example.com.']);

        try {
            $this->migration('2026_05_05_000101_store_every_address_again_now_a_root_label_is_dropped')->up();
            $this->fail('Two logins for one address were merged or rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $first->id, $e->getMessage());
            $this->assertStringContainsString((string) $second->id, $e->getMessage());
        }

        $this->assertSame('ops@example.com.', DB::table('users')->where('id', $second->id)->value('email'));
    }

    private function migration(string $name): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path("migrations/{$name}.php");

        return $migration;
    }
}
