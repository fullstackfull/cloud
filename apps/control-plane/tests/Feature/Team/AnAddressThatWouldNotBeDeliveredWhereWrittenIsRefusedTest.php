<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An address that would not be delivered where it is written is refused where
 * an address is first taken in, and nowhere else (verifier of round eight, on
 * 2698318 and on 9bbf092).
 *
 * Measured at 2698318: `email:rfc,strict` accepts a full-width or small
 * commercial at (U+FF20, U+FE6B) in the domain, UTS #46 maps it to `@`, and
 * `ops@company.test＠evil.test` was stored as `ops@company.test@evil.test` —
 * whose last `@` names another domain, and which the mailer refused to
 * address. A domain ending in a no-break space was stored ending in a
 * space, and a second normalisation trimmed it.
 *
 * Measured at 9bbf092: the rule that refused those required the domain to
 * pass UTS #46, which refuses a label with `--` in its third and fourth
 * positions. Existing logins at `x@mail.ab--cd.example.com`,
 * `x@r3---sn-abc.googlevideo.com` and `u@xn--bad.com` — addresses that do
 * receive mail — were answered 422 on sign-in and on forgot, where they had
 * been answered 200 and 202.
 *
 * Now: the rule (Lynomia\Http\Rules\ALoginAddressThatSplitsWhereWritten,
 * reading LoginAddress::splitsWhereWritten()) refuses only an address whose
 * domain, as stored or as its compatibility form, holds an `@`, a separator
 * or a control character; and only on registration, the operator
 * invitation, the team invitation and the console bootstrap.
 */
final class AnAddressThatWouldNotBeDeliveredWhereWrittenIsRefusedTest extends TeamApiTestCase
{
    private const string PASSWORD = 'Registrants-Own-Password-9!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
        Mail::fake();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wouldNotSplitWhereWritten(): array
    {
        return [
            'full-width commercial at' => ["ops@company.test\u{FF20}evil.test"],
            'small commercial at' => ["ops@company.test\u{FE6B}evil.test"],
            'full-width at beside a label UTS #46 refuses' => ["ops@ab--cd.test\u{FF20}evil.test"],
            'no-break space at the end' => ["ops@example.test\u{00A0}"],
            'en quad inside' => ["ops@exam\u{2000}ple.test"],
            'hair space at the end' => ["ops@example.test\u{200A}"],
            'ideographic space' => ["ops@example\u{3000}.test"],
        ];
    }

    /**
     * Over HTTP the framework's TrimStrings middleware takes Unicode space off
     * both ends of every input before a request sees it, so only what stands
     * inside the domain reaches the rule there.
     *
     * @return array<string, array{0: string}>
     */
    public static function wouldNotSplitWhereWrittenOverHttp(): array
    {
        return array_filter(
            self::wouldNotSplitWhereWritten(),
            static fn (string $key): bool => ! str_ends_with($key, 'at the end'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The verifier's addresses: UTS #46 refuses each (`--` in the third and
     * fourth positions of a label), and each receives mail.
     *
     * @return array<string, array{0: string}>
     */
    public static function refusedByUts46AndDelivered(): array
    {
        return [
            'hyphens in 3-4' => ['x@mail.ab--cd.example.com'],
            'a video host' => ['x@r3---sn-abc.googlevideo.com'],
            'an xn-- label that is not punycode' => ['u@xn--bad.com'],
        ];
    }

    #[Test]
    #[DataProvider('wouldNotSplitWhereWrittenOverHttp')]
    public function every_route_that_takes_an_address_in_refuses_it(string $address): void
    {
        $this->assertFalse(LoginAddress::splitsWhereWritten($address));

        $this->assertRefused($this->postJson('/api/v1/register', $this->registration($address)));
        $this->assertSame(0, User::query()->count(), 'Registration stored the address.');

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $this->assertRefused($this->actingAs($admin)->postJson('/api/admin/operators', [
            'email' => $address, 'name' => 'N', 'roles' => [Role::Noc->value],
        ]));

        [$customer, $owner] = $this->accountWithOwner();
        $this->assertRefused($this->actingAs($owner)->withHeaders($this->actingFor($customer))->postJson('/api/v1/team/invitations', [
            'email' => $address, 'role' => 'member',
        ]));
        $this->assertSame(0, CustomerInvitation::query()->count(), 'The team invitation stored the address.');
        $this->assertSame(2, User::query()->count(), 'An operator invitation stored the address.');
    }

    #[Test]
    #[DataProvider('wouldNotSplitWhereWritten')]
    public function the_console_bootstrap_refuses_it(string $address): void
    {
        $this->artisan('operator:bootstrap', ['email' => $address, '--show-link' => true])
            ->expectsOutputToContain(__('validation.requests.login_address.not_where_written'))
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * Sign-in, forgot and reset only look an address up; they do not refuse it. A
     * login that exists is found however its address was written, and one
     * that does not is answered as any unknown address is.
     */
    #[Test]
    #[DataProvider('wouldNotSplitWhereWrittenOverHttp')]
    public function looking_it_up_is_not_refused(string $address): void
    {
        $this->postJson('/api/v1/password/forgot', ['email' => $address])->assertAccepted();
        $this->postJson('/api/v1/password/reset', [
            'token' => str_repeat('a', 64), 'email' => $address,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'password.reset_failed');
        $this->postJson('/api/v1/login', ['email' => $address, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'auth.invalid_credentials');
    }

    /**
     * An existing login under an address UTS #46 refuses, stored unchanged
     * by the spelling migration, still signs in and still asks for a reset.
     */
    #[Test]
    #[DataProvider('refusedByUts46AndDelivered')]
    public function an_existing_login_uts46_refuses_still_signs_in_and_resets(string $address): void
    {
        $login = User::factory()->create(['password' => self::PASSWORD]);
        DB::table('users')->where('id', $login->id)->update(['email' => $address]);
        $this->spellingMigration()->up();
        $this->assertSame($address, DB::table('users')->where('id', $login->id)->value('email'));

        $this->postJson('/api/v1/login', ['email' => $address, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $login->id);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/password/forgot', ['email' => $address])->assertAccepted();
        Notification::assertSentTo($login, QueuedResetPassword::class);
    }

    #[Test]
    #[DataProvider('refusedByUts46AndDelivered')]
    public function such_an_address_is_registered(string $address): void
    {
        $this->postJson('/api/v1/register', $this->registration($address))->assertAccepted();

        $this->assertSame([$address], User::query()->pluck('email')->all());
    }

    /**
     * Normalising such an address twice still gives one spelling, and the
     * stored address still splits where it was written.
     */
    #[Test]
    #[DataProvider('wouldNotSplitWhereWritten')]
    public function normalising_it_twice_is_normalising_it_once(string $address): void
    {
        $once = LoginAddress::normalise($address);

        $this->assertSame($once, LoginAddress::normalise($once));
        $this->assertSame('ops', substr($once, 0, (int) strrpos($once, '@')), 'The stored address splits somewhere else.');
    }

    /**
     * What the check reads: an address with no `@` has no domain to deliver
     * to; the rest pass.
     */
    #[Test]
    public function what_splits_where_it_is_written(): void
    {
        $this->assertFalse(LoginAddress::splitsWhereWritten('no-at-sign'));

        foreach ([
            'ops@lynomia.test', 'Ärger@ÄRGER.test', 'ops@οδος.gr', 'ops@XN--PXAVBM.gr', 'u@[192.0.2.1]', 'u@[IPv6:2001:db8::1]',
            'ops@ＬＹＮＯＭＩＡ.test', 'u@ex_ample.com', 'u@localhost', 'x@mail.ab--cd.example.com', 'u@xn--bad.com',
        ] as $address) {
            $this->assertTrue(LoginAddress::splitsWhereWritten($address), $address);
        }
    }

    /**
     * A space at the end, sent over HTTP, is trimmed by the request before
     * it is read: the address registered is the one without it.
     */
    #[Test]
    public function a_space_at_the_end_is_trimmed_before_a_request_reads_it(): void
    {
        $this->postJson('/api/v1/register', $this->registration("ops@example.test\u{00A0}"))->assertAccepted();

        $this->assertSame(['ops@example.test'], User::query()->pluck('email')->all());
    }

    #[Test]
    public function the_refusal_is_said_in_arabic_too(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/register', $this->registration("ops@company.test\u{FF20}evil.test"))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email.0', trans('validation.requests.login_address.not_where_written', [], 'ar'));

        $this->assertNotSame(
            trans('validation.requests.login_address.not_where_written', [], 'en'),
            trans('validation.requests.login_address.not_where_written', [], 'ar'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $address): array
    {
        return [
            'name' => 'Registrant', 'email' => $address, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ];
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.fields.email.0', __('validation.requests.login_address.not_where_written'));
    }

    private function spellingMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_05_04_000120_store_every_login_address_in_its_one_spelling.php');

        return $migration;
    }
}
