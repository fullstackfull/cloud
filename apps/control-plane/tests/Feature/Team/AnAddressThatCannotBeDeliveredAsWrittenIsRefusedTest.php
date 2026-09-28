<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An address whose domain, once mapped as a resolver maps it, would no
 * longer split or deliver where it was written is refused at validation, on
 * every route that takes a login address (verifier of round eight, on
 * 2698318).
 *
 * Measured at 2698318: `email:rfc,strict` accepts a full-width or small
 * commercial at (U+FF20, U+FE6B) in the domain, UTS #46 maps it to `@`, and
 * `ops@company.test＠evil.test` was stored as `ops@company.test@evil.test` —
 * whose last `@` names another domain, and which the mailer refused to
 * address (RfcComplianceException: a 500 on a synchronous send). A domain
 * ending in a no-break space was stored ending in a space, and a second
 * normalisation trimmed it: two spellings of one address.
 *
 * One rule (Lynomia\Http\Rules\ALoginAddressThatDelivers, reading
 * LoginAddress::deliversAsWritten()) refuses them with one sentence, en and
 * ar.
 */
final class AnAddressThatCannotBeDeliveredAsWrittenIsRefusedTest extends TeamApiTestCase
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
    public static function undeliverable(): array
    {
        return [
            'full-width commercial at' => ["ops@company.test\u{FF20}evil.test"],
            'small commercial at' => ["ops@company.test\u{FE6B}evil.test"],
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
    public static function undeliverableOverHttp(): array
    {
        return array_filter(
            self::undeliverable(),
            static fn (string $key): bool => ! str_ends_with($key, 'at the end'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    #[Test]
    #[DataProvider('undeliverableOverHttp')]
    public function every_route_that_takes_a_login_address_refuses_it(string $address): void
    {
        $this->assertFalse(LoginAddress::deliversAsWritten($address));

        $this->assertRefused($this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => $address, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ]));
        $this->assertSame(0, User::query()->count(), 'Registration stored the address.');

        $this->assertRefused($this->postJson('/api/v1/login', ['email' => $address, 'password' => self::PASSWORD]));
        $this->assertRefused($this->postJson('/api/v1/password/forgot', ['email' => $address]));
        $this->assertRefused($this->postJson('/api/v1/password/reset', [
            'token' => str_repeat('a', 64), 'email' => $address,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]));

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
    #[DataProvider('undeliverable')]
    public function the_console_bootstrap_refuses_it(string $address): void
    {
        $this->artisan('operator:bootstrap', ['email' => $address, '--show-link' => true])
            ->expectsOutputToContain(__('validation.requests.login_address.undeliverable'))
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * Normalising such an address twice still gives one spelling, whether or
     * not anything lets it through.
     */
    #[Test]
    #[DataProvider('undeliverable')]
    public function normalising_it_twice_is_normalising_it_once(string $address): void
    {
        $once = LoginAddress::normalise($address);

        $this->assertSame($once, LoginAddress::normalise($once));
        $this->assertSame('ops', substr($once, 0, (int) strrpos($once, '@')), 'The stored address splits somewhere else.');
    }

    /**
     * A space at the end, sent over HTTP, is trimmed by the request before
     * it is read: the address registered is the one without it.
     */
    #[Test]
    public function a_space_at_the_end_is_trimmed_before_a_request_reads_it(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Registrant', 'email' => "ops@example.test\u{00A0}", 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'country' => 'KW', 'currency' => 'KWD',
            'accepts_terms' => true, 'account_type' => 'individual',
        ])->assertAccepted();

        $this->assertSame(['ops@example.test'], User::query()->pluck('email')->all());
    }

    /**
     * Positive control: ordinary and internationalised addresses still pass.
     */
    #[Test]
    public function an_address_that_delivers_passes(): void
    {
        foreach (['ops@lynomia.test', 'Ärger@ÄRGER.test', 'ops@οδος.gr', 'ops@XN--PXAVBM.gr', 'u@[192.0.2.1]', 'ops@ＬＹＮＯＭＩＡ.test'] as $address) {
            $this->assertTrue(LoginAddress::deliversAsWritten($address), $address);
        }

        $this->postJson('/api/v1/password/forgot', ['email' => 'ops@οδος.gr'])->assertAccepted();
    }

    /**
     * The sentence is translated, not only English.
     */
    #[Test]
    public function the_refusal_is_said_in_arabic_too(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/password/forgot', ['email' => "ops@company.test\u{FF20}evil.test"])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email.0', trans('validation.requests.login_address.undeliverable', [], 'ar'));

        $this->assertNotSame(
            trans('validation.requests.login_address.undeliverable', [], 'en'),
            trans('validation.requests.login_address.undeliverable', [], 'ar'),
        );
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.fields.email.0', __('validation.requests.login_address.undeliverable'));
    }
}
