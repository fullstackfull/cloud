<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Http\Rules\LoginAddressAtIntake;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An address that grows when it is normalised is measured as it is stored
 * (X9-2, re-audit after round eight).
 *
 * Measured at 6c234dc: `İ` (U+0130) lowercases to `i` + U+0307, two code
 * points for one. 130 of them before `@example.com` are 142 characters —
 * under the operator invitation's `max:255` and the team invitation's
 * `max:254`, which measured the address as typed — and 272 once normalised.
 * The operator invitation answered 500 `server.error` with SQLSTATE 22001
 * (value too long for type character varying(255)) and, with APP_DEBUG on,
 * the SQL text; the team invitation the same. Registration and the console
 * bootstrap measured the normalised address already.
 *
 * Now every route where an address is first taken in validates the spelling
 * it stores, with one set of rules (LoginAddressAtIntake), and the length
 * those rules allow fits every column the address is stored in. That address
 * is refused twice over now: its stored form is longer than MAX_LENGTH, and
 * its local part is longer than the 64 octets `email:rfc,strict` allows,
 * which the invitations did not ask (they used `email` and `email:rfc`).
 * Both are pinned below. Sign-in records the address it was given,
 * normalised, in login_activities; that is clipped to the column.
 */
final class AnAddressThatGrowsWhenNormalisedIsRefusedTest extends TestCase
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

    private static function growing(): string
    {
        return str_repeat("\u{0130}", 130).'@example.com';
    }

    #[Test]
    public function the_address_grows(): void
    {
        $this->assertSame(142, mb_strlen(self::growing()));
        $this->assertSame(272, mb_strlen(LoginAddress::normalise(self::growing())));
    }

    #[Test]
    public function registration_refuses_it_on_the_field(): void
    {
        $this->assertRefusedOnTheField($this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => self::growing(), 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]));

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function the_operator_invitation_refuses_it_on_the_field(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);

        $this->assertRefusedOnTheField($this->actingAs($admin)->postJson('/api/admin/operators', [
            'email' => self::growing(), 'name' => 'N', 'roles' => [Role::Noc->value],
        ]));

        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function the_team_invitation_refuses_it_on_the_field(): void
    {
        $customer = Customer::factory()->organization()->create(['currency' => 'KWD', 'country' => 'KW']);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $customer->members()->create(['user_id' => $owner->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $this->assertRefusedOnTheField($this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->id])
            ->postJson('/api/v1/team/invitations', ['email' => self::growing(), 'role' => 'member']));

        $this->assertSame(0, CustomerInvitation::query()->count());
    }

    #[Test]
    public function the_console_bootstrap_refuses_it(): void
    {
        $this->artisan('operator:bootstrap', ['email' => self::growing(), '--show-link' => true])
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * The same email rule everywhere: a local part of 65 octets is refused
     * by `email:rfc,strict`, which registration applied and the two
     * invitations did not.
     */
    #[Test]
    public function every_route_that_takes_an_address_in_applies_the_same_email_rule(): void
    {
        $address = str_repeat('a', 65).'@example.com';

        $this->assertRefusedOnTheField($this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => $address, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]));

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles([Role::SuperAdmin->value]);
        $this->assertRefusedOnTheField($this->actingAs($admin)->postJson('/api/admin/operators', [
            'email' => $address, 'name' => 'N', 'roles' => [Role::Noc->value],
        ]));

        $customer = Customer::factory()->organization()->create(['currency' => 'KWD', 'country' => 'KW']);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $customer->members()->create(['user_id' => $owner->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);
        $this->assertRefusedOnTheField($this->actingAs($owner)->withHeaders(['X-Lynomia-Customer' => (string) $customer->id])
            ->postJson('/api/v1/team/invitations', ['email' => $address, 'role' => 'member']));

        $this->assertSame(0, User::query()->where('email', $address)->count());
        $this->assertSame(0, CustomerInvitation::query()->count());

        User::query()->delete();
        $this->artisan('operator:bootstrap', ['email' => $address, '--show-link' => true])->assertExitCode(1);
        $this->assertSame(0, User::query()->count());
    }

    /**
     * The length is measured on the stored form, whatever else refuses the
     * address: a stored form one character over MAX_LENGTH is refused by the
     * rules even where the email rule alone would take it.
     */
    #[Test]
    public function the_length_is_the_stored_forms(): void
    {
        $rules = ['email' => LoginAddressAtIntake::rules()];
        $local = str_repeat('a', 60);
        $label = str_repeat('b', 60);
        $atTheLimit = $local.'@'.implode('.', [$label, $label, $label, str_repeat('c', LoginAddressAtIntake::MAX_LENGTH - 61 - 183 - 4)]).'.com';

        $this->assertSame(LoginAddressAtIntake::MAX_LENGTH, mb_strlen($atTheLimit));
        $this->assertTrue(validator(['email' => $atTheLimit], $rules)->passes());
        $this->assertTrue(validator(['email' => 'x'.$atTheLimit], ['email' => ['email:rfc']])->passes());
        $this->assertFalse(validator(['email' => 'x'.$atTheLimit], $rules)->passes());
    }

    /**
     * Sign-in takes no address in; it looks one up, and records the attempt.
     * A failed attempt under the address is answered as any unknown address
     * is, not with a 500.
     */
    #[Test]
    public function sign_in_answers_it_as_an_unknown_address(): void
    {
        $this->postJson('/api/v1/login', ['email' => self::growing(), 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'auth.invalid_credentials');

        $recorded = (string) DB::table('login_activities')->value('email_attempted');
        $this->assertSame(mb_substr(LoginAddress::normalise(self::growing()), 0, 255), $recorded);
    }

    /**
     * The intake rules' length fits every column a login address taken in is
     * stored in, read from the schema rather than assumed.
     */
    #[Test]
    public function the_intake_length_fits_every_column_the_address_is_stored_in(): void
    {
        $columns = [
            ['users', 'email'],
            ['customer_invitations', 'email'],
            ['customers', 'billing_email'],
            ['password_reset_tokens', 'email'],
        ];

        foreach ($columns as [$table, $column]) {
            $length = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('character_maximum_length');

            $this->assertIsInt($length, "{$table}.{$column} has no length.");
            $this->assertGreaterThanOrEqual(LoginAddressAtIntake::MAX_LENGTH, $length, "{$table}.{$column} is shorter than what intake allows.");
        }
    }

    private function assertRefusedOnTheField(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
        $this->assertNotEmpty($response->json('error.details.fields.email'));

        foreach (['SQLSTATE', '22001', 'character varying', 'insert into'] as $internal) {
            $this->assertStringNotContainsString($internal, (string) $response->getContent());
        }
    }
}
