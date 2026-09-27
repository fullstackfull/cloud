<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\OperatorInvitation;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedResetPassword;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mail an operator invitation sends says what the invitation did, and
 * says the same to every invitee (B8-3, re-audit after round seven).
 *
 * Measured at af2bba2: the invitation mailed the framework's reset mail,
 * which ends "If you did not request a password reset, no further action is
 * required" — to a promoted customer whose password, sessions and second
 * factor the invitation had already taken away, and who can sign in again
 * only by following the link.
 */
final class TheInvitationMailSaysWhatTheInvitationDidTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'Registrants-Own-Password-9!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function a_promoted_customer_is_told_the_login_was_taken_away_and_how_to_get_back_in(): void
    {
        $customer = $this->aCustomerWithEverything('promoted@lynomia.test');
        $this->invite($this->delegate(), 'promoted@lynomia.test');

        Notification::assertNotSentTo($customer, QueuedResetPassword::class);
        $mail = $this->mailTo($customer);
        $text = $this->rendered($mail, $customer);

        $this->assertSame(Lang::get('invitations.operator.title', [], 'en'), $mail->toMail($customer)->subject);
        $this->assertStringContainsString('if you already had a login here, its password, signed-in sessions, API tokens and two-factor setup have been removed, and setting a new password is the only way back in', $text);
        $this->assertStringNotContainsString('no further action is required', $text);

        // The link is the broker's reset link, and its token is live.
        $this->assertStringContainsString('/password/reset?token='.urlencode($mail->token), $text);
        $this->assertTrue(Password::broker()->tokenExists($customer->fresh() ?? $customer, $mail->token));
    }

    /**
     * A delegate who can read the invited mailbox learns nothing from the
     * mail about whether the address had a login: a new login's mail and a
     * promoted one's are the same once the token and the address are taken
     * out.
     */
    #[Test]
    public function the_mail_is_the_same_whether_or_not_the_address_had_a_login(): void
    {
        $delegate = $this->delegate();
        $customer = $this->aCustomerWithEverything('had-one@lynomia.test');

        $this->invite($delegate, 'had-one@lynomia.test');
        $this->invite($delegate, 'never-had-one@lynomia.test');
        $new = User::query()->where('email', 'never-had-one@lynomia.test')->sole();

        $normalise = function (User $user): string {
            $mail = $this->mailTo($user);

            return str_replace(
                [$mail->token, urlencode($mail->token), $user->email, urlencode($user->email)],
                'X',
                $mail->toMail($user)->subject.'|'.$this->rendered($mail, $user),
            );
        };

        $this->assertSame($normalise($new), $normalise($customer));
    }

    /**
     * Rendered where a queue worker renders it: in the platform's language,
     * which is not the inviting request's.
     */
    #[Test]
    public function in_arabic_it_says_the_same_right_to_left(): void
    {
        $customer = $this->aCustomerWithEverything('arabic@lynomia.test');
        $this->invite($this->delegate(), 'arabic@lynomia.test');

        $this->app->setLocale('ar');

        $mail = $this->mailTo($customer);
        $text = $this->rendered($mail, $customer);

        $this->assertSame(Lang::get('invitations.operator.title', [], 'ar'), $mail->toMail($customer)->subject);
        $this->assertStringContainsString('ولا سبيل للعودة إليه إلّا بتعيين كلمة مرور جديدة', $text);
        $this->assertStringContainsString('dir="rtl"', $text);
        $this->assertStringNotContainsString('invitations.operator', $text);
    }

    private function aCustomerWithEverything(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => self::PASSWORD]);
        $user->syncRoles([Role::Customer->value]);
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $user->createToken('registrants');

        return $user;
    }

    private function delegate(): User
    {
        $delegate = User::factory()->create(['email_verified_at' => now()]);
        $delegate->syncRoles([Role::Noc->value]);
        $delegate->givePermissionTo(Permission::RoleManage->value);

        return $delegate;
    }

    private function invite(User $actor, string $email): void
    {
        $this->actingAs($actor)
            ->postJson('/api/admin/operators', ['email' => $email, 'name' => 'Invited', 'roles' => [Role::Noc->value]])
            ->assertCreated();
    }

    private function mailTo(User $user): OperatorInvitation
    {
        $sent = Notification::sent($user, OperatorInvitation::class);
        $this->assertCount(1, $sent, 'The invitation did not send its own mail exactly once.');

        /** @var OperatorInvitation $mail */
        $mail = $sent->first();

        return $mail;
    }

    private function rendered(OperatorInvitation $mail, User $user): string
    {
        return html_entity_decode((string) $mail->toMail($user)->render(), ENT_QUOTES);
    }
}
