<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;
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
        // which now belongs to whoever proved the mailbox — and so does the
        // `customer` role: the invitation adds the staff role beside it
        // rather than replacing it, so the person is an operator and still a
        // customer, on both surfaces.
        $membership = CustomerMember::query()->where('user_id', $account->id)->sole();
        $this->assertSame(CustomerRole::Owner, $membership->role);
        $this->assertEqualsCanonicalizing(
            [Role::Customer->value, Role::InfrastructureAdmin->value],
            $account->fresh()?->getRoleNames()->all(),
        );
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/team/members')->assertOk();
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
     * A super admin may know that, and is told; so is the stored name and the
     * login's id.
     *
     * A delegate holding `role.manage` is not told, by any part of the
     * response. Measured at a66ac17 (re-audit after round six): a delegate
     * inviting the address of a login made through POST /api/v1/register —
     * which holds `customer` — was refused 422
     * `rbac.role_not_yours_to_remove`, because the grant replaced the login's
     * roles and so "removed" `customer`, a role no delegate holds; a new
     * address got 201. And for a login holding no role the 201 carried the
     * login's id, a ULID whose first ten characters are its creation time.
     * The round-six version of this test used a role-less factory user,
     * dropped the id before comparing and back-dated only `created_at`, so it
     * could see neither.
     */
    #[Test]
    public function only_a_super_admin_is_told_whether_an_existing_login_was_promoted(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        User::factory()->create(['email' => 'admin-sees-this@lynomia.test', 'name' => 'Registrant Chosen Name']);

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'brand-new@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', false);
        $promoted = $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'admin-sees-this@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', true)
            ->assertJsonPath('data.name', 'Registrant Chosen Name');
        $this->assertSame(
            (string) User::query()->where('email', 'admin-sees-this@lynomia.test')->sole()->id,
            $promoted->json('data.id'),
        );

        $this->freshClient();
        $this->register('registered-customer@lynomia.test', self::SQUATTERS_PASSWORD)->assertAccepted();
        $registered = User::query()->where('email', 'registered-customer@lynomia.test')->sole();
        $this->assertSame([Role::Customer->value], $registered->getRoleNames()->all(), 'Precondition: registration gives the customer role.');

        // A login holding no role, whose id was minted when it was made.
        $roleless = User::factory()->create([
            'id' => strtolower((string) Str::ulid(now()->subDays(400))),
            'email' => 'role-less-login@lynomia.test',
            'name' => 'Registrant Chosen Name',
            'created_at' => now()->subDays(400),
        ]);
        $this->assertSame([], $roleless->getRoleNames()->all());

        // A login holding a role row the enum does not declare, and a
        // permission given to it directly — neither of which a delegate holds.
        SpatieRole::create(['name' => 'legacy', 'guard_name' => 'web']);
        $odd = User::factory()->create(['email' => 'odd-login@lynomia.test']);
        $odd->syncRoles(['legacy']);
        $odd->givePermissionTo(Permission::CustomerViewAny->value);

        $seen = $this->whatDelegatesAreTold(['registered-customer@lynomia.test', 'role-less-login@lynomia.test', 'odd-login@lynomia.test']);

        // The odd login holds exactly what the invitation gave: the unknown
        // role went with the role change, the direct permission with the
        // credentials, and the revocation is on the record.
        $odd = $odd->fresh();
        $this->assertSame([Role::Support->value], $odd?->getRoleNames()->all());
        $this->assertSame([], $odd?->getDirectPermissions()->pluck('name')->all());
        $this->assertSame(
            [Permission::CustomerViewAny->value],
            AuditEntry::query()->where('action', AuditAction::OperatorInvited)->where('subject_id', $odd?->id)->sole()->context['direct_permissions_revoked'] ?? null,
        );

        $this->assertSame(201, $seen['registered-customer@lynomia.test']['status']);
        $body = json_decode($seen['registered-customer@lynomia.test']['body'], true);
        $this->assertNull($body['data']['id']);
        $this->assertNull($body['data']['created_at']);
        $this->assertNull($body['data']['promoted_existing_account']);
        $this->assertSame('Supplied Name', $body['data']['name']);
        $this->assertSame([Role::Support->value], $body['data']['roles']);

        // Both were promoted; the registered customer is still a customer,
        // and each stored name is the login's own.
        $this->assertEqualsCanonicalizing([Role::Customer->value, Role::Support->value], $registered->fresh()?->getRoleNames()->all());
        $this->assertSame([Role::Support->value], $roleless->fresh()?->getRoleNames()->all());
        $this->assertSame('Registrant Chosen Name', $roleless->fresh()?->name);
        $this->assertSame('Registrant', $registered->fresh()?->name);
    }

    /**
     * The address of a soft-deleted login (B7-2, re-audit after round six).
     * The lookup did not see the deleted row and the insert then hit
     * `users_email_unique`: 500, for a super admin and a delegate alike — and
     * for a delegate, a status no new address produces.
     *
     * The row owns the address, so the invitation restores it and promotes it
     * like any other existing login: every credential goes, the reset link is
     * the way in, and whatever staff role it held before it was deleted does
     * not come back with it — it holds exactly the roles this invitation
     * gives (and `customer`, if it was a customer).
     */
    #[Test]
    public function the_address_of_a_deleted_login_is_restored_and_promoted_not_a_server_error(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $gone = User::factory()->create(['email' => 'gone-by-admin@lynomia.test', 'password' => self::SQUATTERS_PASSWORD]);
        $gone->syncRoles([Role::Customer->value, Role::InfrastructureAdmin->value]);
        $gone->delete();

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'gone-by-admin@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', true)
            ->assertJsonPath('data.id', (string) $gone->id)
            ->assertJsonPath('data.roles', [Role::Noc->value]);

        $restored = User::query()->where('email', 'gone-by-admin@lynomia.test')->sole();
        $this->assertNull($restored->deleted_at);
        $this->assertEqualsCanonicalizing([Role::Customer->value, Role::Noc->value], $restored->getRoleNames()->all());
        $this->assertFalse(Hash::check(self::SQUATTERS_PASSWORD, (string) $restored->password));
        Notification::assertSentTo($restored, QueuedResetPassword::class);
        $invited = AuditEntry::query()->where('action', AuditAction::OperatorInvited)->sole();
        $this->assertTrue($invited->context['restored_deleted_login'] ?? false);
        $this->assertSame([Role::InfrastructureAdmin->value], $invited->context['staff_roles_held_when_deleted'] ?? null);

        // A delegate: a deleted login that held a role the delegate does not
        // hold, told apart from a new address by nothing.
        $other = User::factory()->create(['email' => 'gone@lynomia.test']);
        $other->syncRoles([Role::Customer->value, Role::InfrastructureAdmin->value]);
        $other->delete();

        $seen = $this->whatDelegatesAreTold(['gone@lynomia.test']);

        $this->assertSame(201, $seen['gone@lynomia.test']['status']);
        $this->assertEqualsCanonicalizing(
            [Role::Customer->value, Role::Support->value],
            User::query()->where('email', 'gone@lynomia.test')->sole()->getRoleNames()->all(),
        );
    }

    /**
     * PUT /api/admin/operators/{id}/roles sets a login's staff roles, and
     * never takes `customer` away (re-audit after round six: a super admin
     * sending `roles: []` for a registered customer's login emptied it,
     * `customer` included). The route cannot give `customer` either — the
     * request refuses it by name — so a login's customer standing is not
     * something the operator surface changes in either direction.
     */
    #[Test]
    public function changing_roles_never_takes_the_customer_role_away(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $this->register('customer-login@lynomia.test', self::SQUATTERS_PASSWORD)->assertAccepted();
        $login = User::query()->where('email', 'customer-login@lynomia.test')->sole();

        $this->freshClient();
        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$login->id.'/roles', ['roles' => []])
            ->assertOk()
            ->assertJsonPath('data.roles', []);
        $this->assertSame([Role::Customer->value], $login->fresh()?->getRoleNames()->all());

        // Promoted, then demoted: a customer again, not a login with nothing.
        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'customer-login@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value, Role::Support->value]])
            ->assertCreated()
            ->assertJsonPath('data.roles', [Role::Support->value, Role::Noc->value]);
        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$login->id.'/roles', ['roles' => [Role::Support->value]])
            ->assertOk()
            ->assertJsonPath('data.roles', [Role::Support->value]);
        $this->assertEqualsCanonicalizing([Role::Customer->value, Role::Support->value], $login->fresh()?->getRoleNames()->all());

        // A delegate holding the one staff role it has may take that away;
        // `customer` is not theirs to remove and is not removed.
        $delegate = User::factory()->create(['email_verified_at' => now()]);
        $delegate->syncRoles([Role::Support->value]);
        $delegate->givePermissionTo('role.manage');
        $this->freshClient();
        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$login->id.'/roles', ['roles' => []])
            ->assertOk()
            ->assertJsonPath('data.roles', []);
        $this->assertSame([Role::Customer->value], $login->fresh()?->getRoleNames()->all());

        // And a customer login is not re-promoted through this route.
        $this->freshClient();
        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$login->id.'/roles', ['roles' => [Role::Noc->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.not_an_operator');
    }

    /**
     * Only `customer` is kept by a role change. A role row the enum does not
     * declare — no route creates one; a seeder or a SQL client could — is
     * replaced like any other, as it was before `customer` was kept: a super
     * admin removes it, and a delegate who does not hold it is refused.
     */
    #[Test]
    public function a_role_change_still_removes_a_role_the_platform_does_not_declare(): void
    {
        SpatieRole::create(['name' => 'legacy', 'guard_name' => 'web']);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $target = User::factory()->create();
        $target->syncRoles([Role::Customer->value, Role::Noc->value, 'legacy']);

        $delegate = User::factory()->create(['email_verified_at' => now()]);
        $delegate->syncRoles([Role::Noc->value]);
        $delegate->givePermissionTo('role.manage');
        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Noc->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_not_yours_to_remove');
        $this->assertTrue($target->fresh()?->hasRole('legacy'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Support->value]])
            ->assertOk()
            ->assertJsonPath('data.roles', [Role::Support->value]);
        $this->assertEqualsCanonicalizing([Role::Customer->value, Role::Support->value], $target->fresh()?->getRoleNames()->all());
    }

    /**
     * Each address invited by a delegate of its own — so the rate-limit
     * headers count one request each — beside an address that never had a
     * login. Status, every header but Date, and the whole body with the
     * address itself masked must be identical.
     *
     * @param  list<string>  $addresses
     * @return array<string, array{status: int, headers: array<string, mixed>, body: string}>
     */
    private function whatDelegatesAreTold(array $addresses): array
    {
        $control = 'never-had-a-login-'.substr(md5(implode(',', $addresses)), 0, 8).'@lynomia.test';
        $seen = [];

        foreach ([...$addresses, $control] as $address) {
            $delegate = User::factory()->create(['email_verified_at' => now()]);
            $delegate->syncRoles([Role::Support->value]);
            $delegate->givePermissionTo('role.manage');

            $this->freshClient();
            $response = $this->actingAs($delegate)
                ->postJson('/api/admin/operators', ['email' => $address, 'name' => 'Supplied Name', 'roles' => [Role::Support->value]]);

            // Date moves with the clock and X-Request-Id is minted for each
            // request, whatever it asks; a cookie's value is fresh ciphertext
            // on every response, so the cookies are compared by name, path,
            // domain and lifetime rather than by their encrypted bytes.
            $headers = $response->headers->all();
            $this->assertNotEmpty($headers['x-request-id'] ?? null);
            unset($headers['date'], $headers['x-request-id'], $headers['set-cookie']);
            $headers['cookies'] = array_map(
                static fn (Cookie $cookie): string => implode('|', [
                    $cookie->getName(), $cookie->getPath(), (string) $cookie->getDomain(), (string) $cookie->getMaxAge(),
                    $cookie->isSecure() ? 'secure' : '', $cookie->isHttpOnly() ? 'httponly' : '', (string) $cookie->getSameSite(),
                ]),
                $response->headers->getCookies(),
            );
            ksort($headers);

            $seen[$address] = [
                'status' => $response->status(),
                'headers' => $headers,
                'body' => str_replace($address, '<address>', (string) $response->getContent()),
            ];
        }

        $this->assertSame(201, $seen[$control]['status'], 'Precondition: a new address is invited.');

        // All at once, so a failure shows every address that differs.
        $this->assertSame(
            array_fill_keys($addresses, $seen[$control]),
            array_intersect_key($seen, array_flip($addresses)),
            'A delegate can tell an address that has a login from one that has none by the response alone.',
        );

        return $seen;
    }

    /**
     * The invitation reads an existing login under FOR UPDATE, by address,
     * before it writes anything to it: otherwise a concurrent change to the
     * row (a second invitation, a password reset completing) could land
     * between the read and the revocation.
     */
    #[Test]
    public function the_invitation_locks_the_existing_login_before_it_revokes_anything(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $existing = User::factory()->create(['email' => 'locked-first@lynomia.test']);

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => 'locked-first@lynomia.test', 'name' => 'L', 'roles' => [Role::Noc->value]])
            ->assertCreated();

        $lock = null;
        $firstWrite = null;

        foreach ($statements as $index => $sql) {
            if ($lock === null && str_starts_with($sql, 'select') && str_contains($sql, 'from "users"')
                && str_contains($sql, '"email" = ?') && str_contains($sql, 'for update')) {
                $lock = $index;
            }

            if ($firstWrite === null && str_starts_with($sql, 'update "users"')) {
                $firstWrite = $index;
            }
        }

        $this->assertNotNull($lock, 'The existing login was not read FOR UPDATE by its address.');
        $this->assertNotNull($firstWrite, 'Precondition: the revocation wrote the users row.');
        $this->assertLessThan($firstWrite, $lock);
        $this->assertTrue($existing->fresh()?->hasRole(Role::Noc->value));
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
