<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * An operator invitation for an address somebody else registered first (B1,
 * re-audit after round five, unnumbered).
 *
 * POST /api/v1/register asks for no proof of the mailbox, so anybody can hold
 * a customer login under the address a super admin is about to invite.
 * Measured at 00a6e68 (probe r6b/ZzR6bAuthzProbeTest::
 * pre_registered_address_is_promoted_with_the_squatters_password): the
 * registrant signed up as new-operator@lynomia.test (202); the super admin
 * invited that address as infrastructure-admin (201, no sign the account
 * already existed); the row kept the registrant's password; the registrant
 * signed in with it (200) and, the moment the mailbox owner followed the
 * verification link registration had mailed them, read GET
 * /api/admin/customers and GET /api/admin/infrastructure/regions: 200.
 *
 * What holds now, over HTTP: the invitation takes every credential the
 * registrant held away before the roles land — password, session, remember-me
 * cookie, personal access token, second factor — and the only way into the
 * account is the reset link the invitation mails. Completing it proves the
 * mailbox, so it also verifies the address; until then the account is
 * unverified and credential-less, so there is no window.
 */
final class AnInvitationTakesTheAccountFromWhoeverRegisteredTheAddressTest extends TestCase
{
    use RefreshDatabase;

    private const string ADDRESS = 'new-operator@lynomia.test';

    private const string SQUATTERS_PASSWORD = 'Squatter-Password-77';

    private const string OWNERS_PASSWORD = 'The-Mailbox-Owner-Chose-This-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    /**
     * The auditor's probe, as a regression.
     */
    #[Test]
    public function the_registrants_credentials_stop_working_and_the_mailbox_owner_becomes_the_operator(): void
    {
        $this->register(self::ADDRESS, self::SQUATTERS_PASSWORD)->assertAccepted();
        $account = User::query()->where('email', self::ADDRESS)->sole();
        $this->assertNull($account->email_verified_at, 'Precondition: registration proves nothing about the mailbox.');
        $this->assertSame(1, $account->memberships()->count(), 'Precondition: the registrant built a customer account.');

        // Everything the registrant holds before the invitation.
        $login = $this->postJson('/api/v1/login', [
            'email' => self::ADDRESS,
            'password' => self::SQUATTERS_PASSWORD,
            'remember' => true,
        ])->assertOk();
        $recaller = $this->recallerFrom($login->headers->getCookies());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', self::ADDRESS);
        $session = session()->all();

        DB::table('sessions')->insert([
            'id' => 'registrants-other-device',
            'user_id' => $account->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'the registrant',
            'payload' => '',
            'last_activity' => time(),
        ]);
        $token = $account->createToken('registrant')->plainTextToken;
        $account->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['registrant-code-1', 'registrant-code-2'],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $rememberTokenBefore = (string) $account->fresh()?->remember_token;

        // The invitation.
        $this->freshClient();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $invitation = $this->actingAs($admin)->postJson('/api/admin/operators', [
            'email' => self::ADDRESS,
            'name' => 'New Operator',
            'roles' => [Role::InfrastructureAdmin->value],
        ])->assertCreated();

        // The registrant's session, as they left it: dead.
        $this->freshClient();
        $this->withSession($session)->getJson('/api/v1/me')->assertUnauthorized();

        // Their remember-me cookie on a device with no session: dead.
        $this->freshClient();
        $this->withCredentials()
            ->withCookie($recaller['name'], $recaller['value'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->assertNotSame($rememberTokenBefore, (string) $account->fresh()?->remember_token);

        // Their personal access token: dead.
        $this->freshClient();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, $account->tokens()->count());

        // Their other device's session row: gone.
        $this->assertFalse(DB::table('sessions')->where('user_id', $account->id)->exists());

        // Their password: refused, and it opens nothing.
        $this->freshClient();
        $this->postJson('/api/v1/login', ['email' => self::ADDRESS, 'password' => self::SQUATTERS_PASSWORD])
            ->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/customers')->assertUnauthorized();

        // Their second factor: gone, so it cannot stand between the mailbox
        // owner and the account either.
        $account->refresh();
        $this->assertNull($account->two_factor_secret);
        $this->assertNull($account->two_factor_recovery_codes);
        $this->assertNull($account->two_factor_confirmed_at);

        // The account is still unverified: nothing about the invitation proved
        // the mailbox.
        $this->assertNull($account->email_verified_at);

        // What the super admin was told.
        $invitation->assertJsonPath('data.promoted_existing_account', true)
            ->assertJsonPath('data.has_signed_in', false)
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.roles', [Role::InfrastructureAdmin->value]);

        // The mailbox owner follows the link the invitation mailed.
        $this->freshClient();
        $this->postJson('/api/v1/password/reset', [
            'token' => $this->resetTokenMailedTo($account),
            'email' => self::ADDRESS,
            'password' => self::OWNERS_PASSWORD,
            'password_confirmation' => self::OWNERS_PASSWORD,
        ])->assertNoContent();
        $this->assertNotNull($account->fresh()?->email_verified_at, 'Completing the reset proves the mailbox.');

        $this->freshClient();
        $this->postJson('/api/v1/login', ['email' => self::ADDRESS, 'password' => self::OWNERS_PASSWORD])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/customers')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/infrastructure/regions')->assertOk();

        // The customer account the registrant built stays with the login,
        // which now belongs to whoever proved the mailbox.
        $membership = CustomerMember::query()->where('user_id', $account->id)->sole();
        $this->assertSame(CustomerRole::Owner, $membership->role);
    }

    /**
     * The invitation's link is the way in, so a reset link the registrant
     * asked for a moment earlier must not throttle it away: the broker refuses
     * a second link to the same address inside its throttle window, and the
     * invitation would then mail nothing at all.
     */
    #[Test]
    public function a_reset_the_registrant_requested_just_before_does_not_swallow_the_invitations_link(): void
    {
        $this->register(self::ADDRESS, self::SQUATTERS_PASSWORD)->assertAccepted();
        $account = User::query()->where('email', self::ADDRESS)->sole();

        $this->postJson('/api/v1/password/forgot', ['email' => self::ADDRESS])->assertAccepted();
        Notification::assertSentToTimes($account, QueuedResetPassword::class, 1);

        $this->freshClient();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $this->actingAs($admin)->postJson('/api/admin/operators', [
            'email' => self::ADDRESS,
            'name' => 'New Operator',
            'roles' => [Role::Noc->value],
        ])->assertCreated();

        Notification::assertSentToTimes($account, QueuedResetPassword::class, 2);
    }

    /**
     * `promoted_existing_account` says that an address already had a login.
     * A super admin may know that; a delegate holding `role.manage` is told
     * nothing (null), whichever way it went.
     */
    #[Test]
    public function only_a_super_admin_is_told_whether_an_existing_login_was_promoted(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'brand-new@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', false);

        $delegate = User::factory()->create(['email_verified_at' => now()]);
        $delegate->syncRoles([Role::Support->value]);
        $delegate->givePermissionTo('role.manage');
        $existing = User::factory()->create(['email' => 'already-has-a-login@lynomia.test']);

        $this->freshClient();
        $response = $this->actingAs($delegate)
            ->postJson('/api/admin/operators', ['email' => 'already-has-a-login@lynomia.test', 'name' => 'C', 'roles' => [Role::Support->value]])
            ->assertCreated();
        $this->assertArrayHasKey('promoted_existing_account', (array) $response->json('data'));
        $this->assertNull($response->json('data.promoted_existing_account'));
        $this->assertTrue($existing->fresh()?->hasRole(Role::Support->value));
    }

    /**
     * The other door into the same room: the role-change endpoint takes any
     * login's id. Giving a staff role to a login that holds none would promote
     * whoever holds its credentials exactly as the invitation used to, so it is
     * refused and the invitation — which takes the credentials away — is the
     * only way a customer login becomes an operator.
     */
    #[Test]
    public function the_role_change_endpoint_does_not_promote_a_customer_login(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $customer = User::factory()->create(['email' => 'customer@lynomia.test']);
        $customer->syncRoles([Role::Customer->value]);

        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$customer->id.'/roles', ['roles' => [Role::InfrastructureAdmin->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.not_an_operator');

        $this->assertFalse($customer->fresh()?->hasRole(Role::InfrastructureAdmin->value));

        // Positive control: an operator's roles still change through it.
        $operator = User::factory()->create();
        $operator->syncRoles([Role::Noc->value]);
        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$operator->id.'/roles', ['roles' => [Role::Support->value]])
            ->assertOk();
    }

    private function register(string $email, string $password): TestResponse
    {
        return $this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => $email, 'password' => $password,
            'password_confirmation' => $password, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]);
    }

    /**
     * A client holding nothing: no guard user, no session, no header, no
     * cookie — but still the portal's Origin, so it is a stateful client.
     */
    private function freshClient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withHeader('Origin', (string) config('app.url'));
    }

    private function resetTokenMailedTo(User $account): string
    {
        $token = null;

        Notification::assertSentTo($account, QueuedResetPassword::class, static function (QueuedResetPassword $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<int, Cookie>  $cookies
     * @return array{name: string, value: string}
     */
    private function recallerFrom(array $cookies): array
    {
        $recaller = collect($cookies)
            ->first(static fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNotNull($recaller, 'No remember-me cookie was issued.');

        return [
            'name' => $recaller->getName(),
            'value' => CookieValuePrefix::remove(
                app('encrypter')->decrypt((string) $recaller->getValue(), false)
            ),
        ];
    }
}
