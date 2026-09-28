<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\RegistrationAttemptedOnExistingAccount;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A domain written with its root label is the domain without it (B9-1,
 * re-audit after round eight).
 *
 * Measured at 6c234dc: LoginAddress::normalise() put the domain through
 * UTS #46, which maps an ideographic, full-width or half-width full stop
 * (U+3002, U+FF0E, U+FF61) to `.` and keeps a final one as the root label.
 * `ops@EXAMPLE.com。`, which `email:rfc,strict` accepts as typed, was stored
 * `ops@example.com.`: the operator invitation answered 201 with
 * `promoted_existing_account: false` beside the registrant's
 * `ops@example.com` — a second login for one mailbox — sign-in and forgot
 * refused the stored spelling as an invalid address, and the mailer could not
 * address it. The team invitation stored `mate@example.com.` the same way.
 *
 * Now the final root label is dropped from the domain, so the address is one
 * address with the domain written without it; and every route where an
 * address is first taken in validates the spelling it stores.
 */
final class ADomainWrittenWithItsRootLabelIsOneLoginTest extends TestCase
{
    use RefreshDatabase;

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
    public static function rootLabels(): array
    {
        return [
            'ideographic full stop' => ["\u{3002}"],
            'full-width full stop' => ["\u{FF0E}"],
            'half-width ideographic full stop' => ["\u{FF61}"],
            'a full stop' => ['.'],
        ];
    }

    #[Test]
    #[DataProvider('rootLabels')]
    public function the_root_label_is_not_part_of_the_stored_domain(string $stop): void
    {
        $this->assertSame('ops@example.com', LoginAddress::normalise("ops@EXAMPLE.com{$stop}"));
        $this->assertSame('ops@οδος.gr', LoginAddress::normalise("ops@οδος.gr{$stop}"));
        $this->assertSame('ops@ab--cd.com', LoginAddress::normalise("ops@AB--cd.com{$stop}"), 'A domain UTS #46 refuses with its label is stored without its root label all the same.');

        // Two stops are an empty label, not a root label: UTS #46 refuses the
        // domain, and it is kept as typed.
        $this->assertSame("ops@example.com.{$stop}", LoginAddress::normalise("ops@EXAMPLE.com.{$stop}"));

        // Nothing but a root label is taken off: a domain holding nothing
        // else keeps it, and so does one ending in a space.
        $this->assertSame("ops@{$stop}", LoginAddress::normalise("ops@{$stop}"));

        foreach (["ops@EXAMPLE.com{$stop}", "ops@EXAMPLE.com.{$stop}", "ops@{$stop}", "ops@ {$stop}", "ops@AB--cd.com{$stop}"] as $typed) {
            $once = LoginAddress::normalise($typed);
            $this->assertSame($once, LoginAddress::normalise($once), json_encode($typed) ?: $typed);
        }
    }

    /**
     * `﹒` (U+FE52) is not a label separator: UTS #46 refuses a domain
     * holding it, and it is kept as typed, as any domain UTS #46 refuses is.
     */
    #[Test]
    public function a_small_full_stop_is_not_a_root_label(): void
    {
        $this->assertFalse(idn_to_ascii("example.com\u{FE52}", IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46));
        $this->assertSame("ops@example.com\u{FE52}", LoginAddress::normalise("ops@EXAMPLE.com\u{FE52}"));
    }

    #[Test]
    #[DataProvider('rootLabels')]
    public function the_operator_invitation_promotes_the_registrants_login(string $stop): void
    {
        $this->register('ops@example.com')->assertAccepted();
        $login = User::query()->sole();

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $this->actingAs($admin)
            ->postJson('/api/admin/operators', ['email' => "ops@EXAMPLE.com{$stop}", 'name' => 'N', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', true)
            ->assertJsonPath('data.id', (string) $login->id)
            ->assertJsonPath('data.email', 'ops@example.com');

        $this->assertSame(['ops@example.com'], User::query()->whereKeyNot($admin->id)->pluck('email')->all());
    }

    #[Test]
    #[DataProvider('rootLabels')]
    public function registration_under_the_root_label_makes_no_second_login(string $stop): void
    {
        $this->register('ops@example.com')->assertAccepted();
        $login = User::query()->sole();

        $this->freshClient();
        $this->register("ops@EXAMPLE.com{$stop}")->assertAccepted();

        $this->assertSame(['ops@example.com'], User::query()->pluck('email')->all());
        Notification::assertSentTo($login, RegistrationAttemptedOnExistingAccount::class);
    }

    #[Test]
    #[DataProvider('rootLabels')]
    public function the_team_invitation_stores_the_domain_without_it(string $stop): void
    {
        $customer = Customer::factory()->organization()->create(['currency' => 'KWD', 'country' => 'KW']);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $customer->members()->create(['user_id' => $owner->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->id])
            ->postJson('/api/v1/team/invitations', ['email' => "mate@EXAMPLE.com{$stop}", 'role' => 'member'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'mate@example.com');

        $this->assertSame(['mate@example.com'], CustomerInvitation::query()->pluck('email')->all());
    }

    /**
     * The root label a registrant did not type is no obstacle to signing in
     * with it, nor the other way round.
     */
    #[Test]
    public function sign_in_finds_the_login_under_the_root_label(): void
    {
        $this->register('ops@example.com')->assertAccepted();
        $login = User::query()->sole();

        $this->freshClient();
        $this->postJson('/api/v1/login', ['email' => "ops@example.com\u{3002}", 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $login->id);
    }

    #[Test]
    public function the_console_bootstrap_refuses_the_root_label_spelling_of_an_existing_login(): void
    {
        User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('operator:bootstrap', ['email' => "ops@EXAMPLE.com\u{3002}", '--show-link' => true])
            ->expectsOutputToContain('That address already belongs to an account on this deployment.')
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
    }

    private function register(string $email): TestResponse
    {
        return $this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => $email, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]);
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
