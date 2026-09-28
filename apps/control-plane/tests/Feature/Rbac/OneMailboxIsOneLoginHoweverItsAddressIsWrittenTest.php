<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Application\Actions\InviteMember;
use Lynomia\Modules\Identity\Application\Actions\RegisterCustomer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;
use Lynomia\Modules\Identity\Infrastructure\Notifications\RegistrationAttemptedOnExistingAccount;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * One mailbox is one login, however its address was written (R4, verifier of
 * round eight).
 *
 * Measured at 82d1271: registration stored the address through strtolower(),
 * which lowercases ASCII only, and the invitation looked it up through
 * Str::lower(), which lowercases everything. Registering `Ärger@…` stored
 * `Ärger@…`; inviting `ärger@…` found no login and made a second one — 201,
 * two rows for one mailbox — and the registrant's login kept its password,
 * so the invitation took nothing from whoever registered the address. The
 * other way round, an address invited first could be registered again under
 * another spelling. A decomposed `A` + U+0308 differed from `Ä` under both.
 *
 * Every path that stores or looks up a login address now goes through
 * LoginAddress::normalise() (NFC, then lowercase); the User model applies it
 * to every address it stores.
 */
final class OneMailboxIsOneLoginHoweverItsAddressIsWrittenTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'Registrants-Own-Password-9!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spellings(): array
    {
        return [
            'registered upper, invited lower' => ['Ärger@lynomia.test', 'ärger@lynomia.test'],
            'registered lower, invited upper' => ['ärger@lynomia.test', 'Ärger@lynomia.test'],
            'registered decomposed, invited composed' => ["A\u{0308}rger@lynomia.test", 'ärger@lynomia.test'],
            'registered composed, invited decomposed' => ['Ärger@lynomia.test', "a\u{0308}rger@lynomia.test"],
            'a non-ASCII domain in capitals' => ['owner@ÄRGER.test', 'Owner@ärger.test'],
        ];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function the_invitation_promotes_the_registrants_login_and_takes_its_credentials(string $registered, string $invited): void
    {
        $this->register($registered)->assertAccepted();
        $this->assertSame(1, User::query()->count());
        $login = User::query()->sole();
        $login->createToken('registrants');

        $admin = $this->operator(super: true);
        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => $invited, 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', true)
            ->assertJsonPath('data.id', (string) $login->id);

        $this->assertSame(1, User::query()->whereKeyNot($admin->id)->count(), 'The invitation made a second login for one mailbox.');
        $promoted = $login->fresh();
        $this->assertNotNull($promoted);
        $this->assertTrue($promoted->hasRole(Role::Noc->value));
        $this->assertFalse(Hash::check(self::PASSWORD, (string) $promoted->password), 'The registrant\'s password still works.');
        $this->assertSame(0, $promoted->tokens()->count());
    }

    /**
     * The other order: an address invited first cannot be registered again
     * under another spelling of it.
     */
    #[Test]
    #[DataProvider('spellings')]
    public function a_registration_after_the_invitation_makes_no_second_login(string $registered, string $invited): void
    {
        $this->actingAs($this->operator(super: true))
            ->postJson('/api/admin/operators', ['email' => $invited, 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated();
        $operator = User::query()->where('email', LoginAddress::normalise($invited))->sole();

        $this->freshClient();
        $this->register($registered)->assertAccepted();

        $this->assertSame(0, User::query()->whereKeyNot($operator->id)->whereDoesntHave('roles', static fn ($q) => $q->where('name', Role::SuperAdmin->value))->count(), 'Registration made a second login for one mailbox.');
        Notification::assertSentTo($operator, RegistrationAttemptedOnExistingAccount::class);
    }

    /**
     * The registration action normalises what it is given, whoever calls it:
     * the request's own normalisation is not what keeps the second login out.
     */
    #[Test]
    public function the_registration_action_finds_the_login_under_another_spelling(): void
    {
        $existing = User::factory()->create(['email' => 'ärger@lynomia.test']);

        $result = app(RegisterCustomer::class)->execute([
            'name' => 'Registrant', 'email' => "A\u{0308}RGER@lynomia.test", 'password' => self::PASSWORD,
            'country' => 'KW', 'currency' => 'KWD', 'account_type' => 'individual',
        ]);

        $this->assertNull($result);
        $this->assertSame(1, User::query()->count());
        Notification::assertSentTo($existing, RegistrationAttemptedOnExistingAccount::class);
    }

    /**
     * Signing in and asking for a reset find the login under any spelling.
     */
    #[Test]
    #[DataProvider('spellings')]
    public function sign_in_and_reset_find_the_login_under_either_spelling(string $registered, string $other): void
    {
        $this->register($registered)->assertAccepted();
        $login = User::query()->sole();

        foreach ([$registered, $other] as $spelling) {
            $this->freshClient();
            $this->postJson('/api/v1/login', ['email' => $spelling, 'password' => self::PASSWORD])
                ->assertOk()
                ->assertJsonPath('data.id', (string) $login->id);
        }

        $this->freshClient();
        $this->postJson('/api/v1/password/forgot', ['email' => $other])->assertAccepted();
        Notification::assertSentTo($login, QueuedResetPassword::class);
    }

    /**
     * The sign-in limiter counts one address under every spelling: five
     * failures as `Ärger@…` leave none for `ärger@…`.
     */
    #[Test]
    public function the_sign_in_limiter_counts_every_spelling_as_one_address(): void
    {
        $attempts = (int) config('security.rate_limits.login.attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->freshClient();
            $this->postJson('/api/v1/login', ['email' => 'Ärger@nowhere.test', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->freshClient();
        $this->postJson('/api/v1/login', ['email' => "a\u{0308}rger@nowhere.test", 'password' => 'wrong'])->assertTooManyRequests();
    }

    /**
     * B7-1 across spellings: a delegate inviting another spelling of a
     * registered address is answered exactly as for an address nobody holds.
     */
    #[Test]
    public function a_delegate_cannot_tell_a_registered_spelling_from_a_new_address(): void
    {
        $this->register('Ärger@lynomia.test')->assertAccepted();
        $delegate = $this->operator(super: false);

        $seen = [];

        foreach (['ärger@lynomia.test', 'nobody@lynomia.test'] as $address) {
            $this->freshClient();
            $response = $this->actingAs($delegate)
                ->postJson('/api/admin/operators', ['email' => $address, 'name' => 'Supplied', 'roles' => [Role::Noc->value]])
                ->assertCreated();
            $this->assertSame($address, $response->json('data.email'));
            $seen[] = str_replace(json_encode($address), '"<A>"', (string) $response->getContent());
        }

        $this->assertSame($seen[1], $seen[0]);
        $this->assertSame(1, User::query()->where('email', 'ärger@lynomia.test')->count());
    }

    /**
     * The model stores the one spelling whichever way the address is set, so
     * a path that forgets to normalise cannot make a second login.
     */
    #[Test]
    public function a_login_stored_any_way_is_stored_in_the_one_spelling(): void
    {
        $made = User::factory()->create(['email' => " A\u{0308}RGER@Lynomia.Test "]);
        $this->assertSame('ärger@lynomia.test', DB::table('users')->where('id', $made->id)->value('email'));

        $filled = User::factory()->create();
        $filled->forceFill(['email' => 'ÖL@lynomia.test'])->save();
        $this->assertSame('öl@lynomia.test', DB::table('users')->where('id', $filled->id)->value('email'));
    }

    /**
     * A team invitation finds an existing member under any spelling of the
     * member's address.
     */
    #[Test]
    public function a_team_invitation_knows_a_member_under_another_spelling(): void
    {
        $customer = Customer::factory()->organization()->create(['currency' => 'KWD', 'country' => 'KW']);
        $member = User::factory()->create(['email' => 'ärger@lynomia.test']);
        $customer->members()->create(['user_id' => $member->id, 'role' => CustomerRole::Member, 'accepted_at' => now()]);

        try {
            app(InviteMember::class)->execute($customer, "A\u{0308}RGER@lynomia.test", CustomerRole::Technical);
            $this->fail('A member was offered membership again under another spelling of their address.');
        } catch (MembershipRefusedException $e) {
            $this->assertSame('membership.already_a_member', $e->errorCode());
        }
    }

    /**
     * A row written before the spelling was one — registration kept `Ä` —
     * is rewritten by the migration, and is then found and promoted by an
     * invitation of any spelling.
     */
    #[Test]
    public function the_migration_rewrites_a_legacy_spelling_so_the_invitation_finds_it(): void
    {
        $legacy = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();
        DB::table('users')->where('id', $legacy->id)->update(['email' => "A\u{0308}RGER@lynomia.test"]);
        DB::table('users')->where('id', $deleted->id)->update(['email' => 'Gone@ÄRGER.test']);
        $untouched = User::factory()->create(['email' => 'plain@lynomia.test']);

        $this->spellingMigration()->up();

        $this->assertSame('ärger@lynomia.test', DB::table('users')->where('id', $legacy->id)->value('email'));
        $this->assertSame('gone@ärger.test', DB::table('users')->where('id', $deleted->id)->value('email'));
        $this->assertSame('plain@lynomia.test', DB::table('users')->where('id', $untouched->id)->value('email'));

        $this->actingAs($this->operator(super: true))
            ->postJson('/api/admin/operators', ['email' => 'Ärger@lynomia.test', 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.id', (string) $legacy->id);
    }

    /**
     * Two rows that are one address once normalised are not merged: the
     * migration names both and changes nothing.
     */
    #[Test]
    public function the_migration_refuses_two_logins_that_are_one_address_and_changes_nothing(): void
    {
        $upper = User::factory()->create();
        $lower = User::factory()->create(['email' => 'ärger@lynomia.test']);
        $other = User::factory()->create();
        DB::table('users')->where('id', $upper->id)->update(['email' => 'Ärger@lynomia.test']);
        DB::table('users')->where('id', $other->id)->update(['email' => 'Other@lynomia.test']);
        $before = DB::table('users')->orderBy('id')->pluck('email', 'id')->all();

        try {
            $this->spellingMigration()->up();
            $this->fail('Two logins for one address were silently merged or rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $upper->id, $e->getMessage());
            $this->assertStringContainsString((string) $lower->id, $e->getMessage());
            $this->assertStringNotContainsString((string) $other->id, $e->getMessage());
        }

        $this->assertSame($before, DB::table('users')->orderBy('id')->pluck('email', 'id')->all());
    }

    private function spellingMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_05_04_000120_store_every_login_address_in_its_one_spelling.php');

        return $migration;
    }

    private function register(string $email): TestResponse
    {
        return $this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => $email, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]);
    }

    private function operator(bool $super): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        if ($super) {
            $user->syncRoles([Role::SuperAdmin->value]);

            return $user;
        }

        $user->syncRoles([Role::Noc->value]);
        $user->givePermissionTo(Permission::RoleManage->value);

        return $user;
    }

    private function freshClient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withHeader('Origin', (string) config('app.url'));
    }
}
