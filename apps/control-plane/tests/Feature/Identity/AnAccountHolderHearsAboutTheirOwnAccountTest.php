<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Application\Actions\NotifyAboutAccountSecurity;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\LoginActivity;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Queries\NotificationsVisibleTo;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * F-46: the account-security notifications, which were declared, translated
 * into both languages, and raised by nothing.
 *
 * A password changed, a second factor switched on or off, a sign-in from
 * somewhere the account has never signed in from: these are how the holder of
 * an account notices that somebody else is using it, and every screen that
 * performs them existed and told nobody. What is pinned here is that each one
 * now tells exactly one person, once — the person whose account it is — and
 * that nobody else on the same customer account can read it.
 */
final class AnAccountHolderHearsAboutTheirOwnAccountTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    private const string INJECTED = 'injected by the test: ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('login');
    }

    // ---- the password ----------------------------------------------------

    #[Test]
    public function changing_the_password_tells_the_person_it_belongs_to(): void
    {
        Mail::fake();
        [$customer, $user] = $this->account(['billing_email' => 'finance@example.com']);
        $this->actingAs($user);

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $told = $this->told($user, NotificationType::PasswordChanged);
        $this->assertCount(1, $told);
        $this->assertSame((string) $customer->getKey(), $told->first()->customer_id);
        $this->assertSame(NotificationCategory::Security, $told->first()->category);

        /*
         * To the person, not the account's billing mailbox: on a business
         * account that is a finance team, who did not change anybody's
         * password and cannot act on the warning.
         */
        Mail::assertSent(NotificationMail::class, static fn (NotificationMail $mail): bool => $mail->hasTo($user->email));
        Mail::assertNotSent(NotificationMail::class, static fn (NotificationMail $mail): bool => $mail->hasTo('finance@example.com'));
    }

    #[Test]
    public function a_reset_password_tells_them_as_well(): void
    {
        [, $user] = $this->account();
        $token = Password::createToken($user);

        $this->postJson(route('api.v1.password.reset'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $this->assertCount(1, $this->told($user, NotificationType::PasswordChanged));
    }

    #[Test]
    public function a_refused_password_change_tells_nobody_anything(): void
    {
        [, $user] = $this->account();
        $this->actingAs($user);

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'not-my-password',
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertStatus(422);

        $this->assertSame(0, Notification::query()->count());
    }

    // ---- the second factor -----------------------------------------------

    #[Test]
    public function switching_two_factor_on_tells_them(): void
    {
        [, $user] = $this->account();
        $this->actingAs($user);

        $secret = $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])
            ->assertOk()
            ->json('data.secret');

        // Beginning enrolment protects nothing yet, so it is not news.
        $this->assertCount(0, $this->told($user, NotificationType::TwoFactorEnabled));

        $this->postJson(route('api.v1.me.2fa.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk();

        $this->assertCount(1, $this->told($user, NotificationType::TwoFactorEnabled));
    }

    #[Test]
    public function switching_two_factor_off_tells_them(): void
    {
        [, $user] = $this->account([], [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        $this->deleteJson(route('api.v1.me.2fa.disable'), ['current_password' => self::PASSWORD])->assertNoContent();

        $this->assertCount(1, $this->told($user, NotificationType::TwoFactorDisabled));
    }

    #[Test]
    public function an_enrolment_nobody_confirmed_is_not_announced_as_switched_on(): void
    {
        /*
         * Asked of the action, not the screen: the confirm screen only calls
         * it once the code has been accepted, so through HTTP this guard is
         * never the thing that decides. The action promises it on its own.
         */
        [, $user] = $this->account([], ['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        app(NotifyAboutAccountSecurity::class)->twoFactorEnabled($user);

        $this->assertCount(0, $this->told($user, NotificationType::TwoFactorEnabled));
    }

    #[Test]
    public function switching_off_what_was_never_on_tells_them_nothing(): void
    {
        // An enrolment begun and never confirmed: a secret, and no protection.
        [, $user] = $this->account([], ['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($user);

        $this->deleteJson(route('api.v1.me.2fa.disable'), ['current_password' => self::PASSWORD])->assertNoContent();

        $this->assertCount(0, $this->told($user, NotificationType::TwoFactorDisabled));
    }

    // ---- signing in --------------------------------------------------------

    #[Test]
    public function a_sign_in_from_an_address_and_browser_never_seen_is_announced(): void
    {
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $this->signIn($user, '203.0.113.9', 'Chrome somewhere else')->assertOk();

        $told = $this->told($user, NotificationType::NewSignIn);
        $this->assertCount(1, $told);
        $this->assertSame('203.0.113.9', $told->first()->data['location'] ?? null);
    }

    #[Test]
    public function a_new_address_with_a_familiar_browser_is_still_announced(): void
    {
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $this->signIn($user, '203.0.113.9', 'Firefox at home')->assertOk();

        $this->assertCount(1, $this->told($user, NotificationType::NewSignIn));
    }

    #[Test]
    public function a_familiar_address_in_a_new_browser_is_still_announced(): void
    {
        /*
         * The other half of "the address and the browser": the same office
         * address with a browser this person has never used there. A shared
         * address — an office, a carrier's NAT — is exactly where somebody
         * else's machine turns up.
         */
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $this->signIn($user, '198.51.100.7', 'Chrome somewhere else')->assertOk();

        $this->assertCount(1, $this->told($user, NotificationType::NewSignIn));
    }

    #[Test]
    public function a_sign_in_from_where_they_always_sign_in_is_not(): void
    {
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $this->signIn($user, '198.51.100.7', 'Firefox at home')->assertOk();

        $this->assertCount(0, $this->told($user, NotificationType::NewSignIn));
    }

    #[Test]
    public function the_first_sign_in_an_account_ever_makes_is_not_announced(): void
    {
        [, $user] = $this->account();

        $this->signIn($user, '203.0.113.9', 'Chrome somewhere else')->assertOk();

        $this->assertCount(0, $this->told($user, NotificationType::NewSignIn));
    }

    #[Test]
    public function a_refused_sign_in_is_not_a_sign_in(): void
    {
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Chrome somewhere else')
            ->postJson(route('api.v1.login'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422);

        $this->assertCount(0, $this->told($user, NotificationType::NewSignIn));
    }

    #[Test]
    public function the_second_factor_path_announces_it_too(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        [, $user] = $this->account([], ['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');

        $challenge = $this->signIn($user, '203.0.113.9', 'Chrome somewhere else')
            ->assertStatus(403)
            ->json('error.details.challenge_token');

        // The password alone signed nobody in, so nothing is announced yet.
        $this->assertCount(0, $this->told($user, NotificationType::NewSignIn));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Chrome somewhere else')
            ->postJson(route('api.v1.login.two_factor'), [
                'challenge_token' => $challenge,
                'code' => app(Google2FA::class)->getCurrentOtp($secret),
            ])->assertOk();

        $this->assertCount(1, $this->told($user, NotificationType::NewSignIn));
    }

    // ---- who can read it -------------------------------------------------

    #[Test]
    public function a_teammate_on_the_same_account_never_sees_it(): void
    {
        [$customer, $owner] = $this->account();
        $colleague = $this->memberOf($customer, CustomerRole::Administrator, 'colleague@example.com');

        $this->actingAs($owner);
        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $notification = $this->told($owner, NotificationType::PasswordChanged)->sole();

        // The session now carries the new password's hash; an instance still
        // holding the old one would be signed out by Sanctum, correctly.
        $owner->refresh();

        // The owner sees it, in the list, in the count and on the dashboard.
        $this->actingAs($owner)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.unread', 1);
        $this->actingAs($owner)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingAs($owner)->getJson('/api/v1/me/overview')->assertOk()->assertJsonPath('data.unread_notifications', 1);

        /*
         * The colleague does not: "your password was changed" in somebody
         * else's inbox is a false alarm about their own account, and a
         * sign-in notice there would hand them the owner's address.
         */
        // A browser of their own: the session above belongs to the owner.
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($colleague)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.unread', 0);
        $this->actingAs($colleague)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 0);
        $this->actingAs($colleague)->getJson('/api/v1/me/overview')->assertOk()->assertJsonPath('data.unread_notifications', 0);
        $this->actingAs($colleague)->postJson('/api/v1/notifications/'.$notification->getKey().'/read')->assertNotFound();
        $this->actingAs($colleague)->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertNull($notification->refresh()->read_at, 'A colleague marked the owner\'s security notice read.');
    }

    #[Test]
    public function somebody_who_belongs_to_no_account_is_not_refused_for_it(): void
    {
        /*
         * An operator, or an invitee who has not accepted yet. A notification
         * belongs to a customer account, so there is nowhere to put one — the
         * boundary this repair could not cross — and the change itself must
         * still go through.
         */
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->actingAs($user);

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function it_is_raised_in_the_account_they_joined_first_and_only_there(): void
    {
        [$first, $user] = $this->account();

        $this->travel(1)->days();
        $second = Customer::factory()->create();
        $second->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Administrator, 'accepted_at' => now()]);

        $this->actingAs($user);
        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        // Once, not once per account: one change, one email.
        $told = $this->told($user, NotificationType::PasswordChanged);
        $this->assertCount(1, $told);
        $this->assertSame((string) $first->getKey(), $told->first()->customer_id);
        $this->assertSame(1, $told->first()->deliveries()->where('channel', NotificationChannel::Email->value)->count());
    }

    #[Test]
    public function a_security_notice_that_names_nobody_stays_the_whole_accounts(): void
    {
        /*
         * Only a notice that names a person is narrowed to that person. One
         * that names nobody is about the account, and hiding it from everyone
         * on it would hide it from everyone.
         */
        [$customer, $owner] = $this->account();
        $colleague = $this->memberOf($customer, CustomerRole::Administrator, 'colleague@example.com');

        $accountWide = Notification::factory()->ofType(NotificationType::PasswordChanged)->create(['customer_id' => $customer->id]);
        $personal = Notification::factory()->ofType(NotificationType::PasswordChanged)->create([
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
        ]);

        $seenBy = static fn (User $viewer): array => app(NotificationsVisibleTo::class)
            ->query((string) $customer->getKey(), (string) $viewer->getKey())
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([(string) $accountWide->getKey()], $seenBy($colleague));
        $this->assertEqualsCanonicalizing([(string) $accountWide->getKey(), (string) $personal->getKey()], $seenBy($owner));
    }

    // ---- when telling them fails -------------------------------------------
    //
    // NotifyAboutAccountSecurity promises that nothing in it refuses or throws:
    // the change has already happened, and failing to announce it must not
    // undo or block it. Each moment it announces is driven here with every
    // query the announcement makes failing — the membership lookup, the
    // sign-in history, and the notification's own rows alike — and the change
    // must stand, the person must get what they asked for, and the failure
    // must be reported rather than swallowed.

    #[Test]
    public function the_password_change_stands_when_telling_them_fails(): void
    {
        Exceptions::fake();
        [, $user] = $this->account();
        $this->actingAs($user);
        $this->tellingThemFails();

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-secret-1',
            'password_confirmation' => 'a-brand-new-secret-1',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('a-brand-new-secret-1', (string) $user->refresh()->password));
        $this->assertSame(0, Notification::query()->count());
        $this->assertTheFailureWasReported();
    }

    #[Test]
    public function the_sign_in_stands_when_telling_them_fails(): void
    {
        Exceptions::fake();
        [, $user] = $this->account();
        $this->signedInBefore($user, '198.51.100.7', 'Firefox at home');
        $this->tellingThemFails();

        $this->signIn($user, '203.0.113.9', 'Chrome somewhere else')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, Notification::query()->count());
        $this->assertTheFailureWasReported();
    }

    #[Test]
    public function switching_two_factor_on_stands_when_telling_them_fails(): void
    {
        Exceptions::fake();
        [, $user] = $this->account();
        $this->actingAs($user);

        $secret = $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])
            ->assertOk()
            ->json('data.secret');

        $this->tellingThemFails();

        // The recovery codes are in this response and nowhere else: a 500
        // here would leave the second factor on and the codes lost.
        $this->postJson(route('api.v1.me.2fa.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['data' => ['recovery_codes']]);

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
        $this->assertSame(0, Notification::query()->count());
        $this->assertTheFailureWasReported();
    }

    #[Test]
    public function switching_two_factor_off_stands_when_telling_them_fails(): void
    {
        Exceptions::fake();
        [, $user] = $this->account([], [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);
        $this->tellingThemFails();

        $this->deleteJson(route('api.v1.me.2fa.disable'), ['current_password' => self::PASSWORD])->assertNoContent();

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
        $this->assertSame(0, Notification::query()->count());
        $this->assertTheFailureWasReported();
    }

    #[Test]
    public function a_change_that_is_rolled_back_is_never_announced(): void
    {
        /*
         * "The change has already happened" is made true rather than assumed:
         * a caller that announces inside its own transaction is announced for
         * once that transaction commits, and not at all if it rolls back.
         * Otherwise the email leaves for a password that was never changed,
         * and a failed announcement inside the transaction could take the
         * change down with it.
         */
        Mail::fake();
        [, $user] = $this->account();

        try {
            DB::transaction(static function () use ($user): void {
                app(NotifyAboutAccountSecurity::class)->passwordChanged($user);

                throw new RuntimeException('the change failed after it was announced');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(0, $this->told($user, NotificationType::PasswordChanged));
        Mail::assertNotSent(NotificationMail::class);

        // And the same call in a transaction that commits is announced, after it.
        DB::transaction(function () use ($user): void {
            app(NotifyAboutAccountSecurity::class)->passwordChanged($user);

            $this->assertCount(0, $this->told($user, NotificationType::PasswordChanged), 'Announced before the change committed.');
        });

        $this->assertCount(1, $this->told($user, NotificationType::PasswordChanged));
    }

    // ---- plumbing ----------------------------------------------------------

    /**
     * Every query made from inside NotifyAboutAccountSecurity — and so from
     * NotifyCustomer when it calls that — fails.
     *
     * Before the statement reaches the database, not after: a statement
     * PostgreSQL refused would abort the transaction this test runs inside,
     * and everything after it — the controller's own work and this test's
     * assertions — would then fail for that reason rather than the one under
     * test. Chosen by the call stack, so the sign-in, the password change and
     * the second factor themselves are untouched and only telling the person
     * fails.
     */
    private function tellingThemFails(): void
    {
        DB::beforeExecuting(static function (string $sql): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === NotifyAboutAccountSecurity::class) {
                    throw new RuntimeException(self::INJECTED.$sql);
                }
            }
        });
    }

    private function assertTheFailureWasReported(): void
    {
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => str_starts_with($e->getMessage(), self::INJECTED),
        );
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $user
     * @return array{0: Customer, 1: User}
     */
    private function account(array $customer = [], array $user = []): array
    {
        $account = Customer::factory()->create($customer + ['currency' => 'KWD', 'country' => 'KW']);
        $holder = User::factory()->create($user + [
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $account->members()->create([
            'user_id' => $holder->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$account, $holder];
    }

    private function memberOf(Customer $customer, CustomerRole $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make(self::PASSWORD)]);

        $customer->members()->create(['user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function signedInBefore(User $user, string $ip, string $userAgent): void
    {
        LoginActivity::query()->create([
            'user_id' => $user->id,
            'email_attempted' => $user->email,
            'outcome' => LoginOutcome::Success,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now()->subWeek(),
        ]);
    }

    private function signIn(User $user, string $ip, string $userAgent): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $userAgent)
            ->postJson(route('api.v1.login'), ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    /**
     * @return Collection<int, Notification>
     */
    private function told(User $user, NotificationType $type): Collection
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->get();
    }
}
