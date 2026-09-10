<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedVerifyEmail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first three minutes of a customer's life on this platform.
 *
 * They have registered, they have not opened their mail yet, and they are
 * looking at prices. Two things were wrong with what they used to be shown:
 * the catalogue refused them, and every refusal read "you are not permitted to
 * perform this action" — the same sentence a customer gets for asking to do
 * something their role forbids, with no mention of the verification link that
 * is the actual next step.
 *
 * So: the catalogue is readable, everything that spends money is not, and the
 * refusal says which refusal it is.
 */
final class AnUnverifiedCustomerCanLookButNotBuyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function unverifiedAccount(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->unverified()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    #[Test]
    public function they_can_read_the_catalogue(): void
    {
        [, $user] = $this->unverifiedAccount();

        $this->actingAs($user)->getJson('/api/v1/catalog/products')->assertOk();
    }

    #[Test]
    public function they_cannot_reach_anything_that_moves_money_or_provisions_hardware(): void
    {
        [, $user] = $this->unverifiedAccount();

        $refused = [
            ['GET', '/api/v1/orders'],
            ['GET', '/api/v1/invoices'],
            ['GET', '/api/v1/subscriptions'],
            ['GET', '/api/v1/wallet'],
            ['GET', '/api/v1/wallet/transactions'],
            ['GET', '/api/v1/payments'],
            ['GET', '/api/v1/services'],
            ['POST', '/api/v1/orders'],
            ['POST', '/api/v1/orders/quote'],
        ];

        foreach ($refused as [$method, $path]) {
            $this->actingAs($user)
                ->json($method, $path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'auth.email_unverified', "{$method} {$path}");
        }
    }

    #[Test]
    public function the_refusal_is_told_apart_from_a_permissions_refusal(): void
    {
        [$customer, $user] = $this->unverifiedAccount();

        $unverified = $this->actingAs($user)->getJson('/api/v1/invoices')->assertStatus(403);

        // A verified member whose role does not allow the action gets the other
        // refusal, with a different code, so a screen can tell them apart.
        $verified = User::factory()->create();
        $customer->members()->create([
            'user_id' => $verified->id,
            // A role that may look at technical things and not at money.
            'role' => CustomerRole::Technical,
            'accepted_at' => now(),
        ]);

        $forbidden = $this->actingAs($verified)->getJson('/api/v1/invoices')->assertStatus(403);

        $this->assertSame('auth.email_unverified', $unverified->json('error.code'));
        $this->assertNotSame($unverified->json('error.code'), $forbidden->json('error.code'));

        // And the unverified refusal names the address the link went to, so the
        // screen can say where to look without a second request.
        $this->assertSame(
            $user->email,
            $unverified->json('error.details.email'),
        );
    }

    #[Test]
    public function they_can_ask_for_the_link_again(): void
    {
        Notification::fake();

        [, $user] = $this->unverifiedAccount();

        $this->actingAs($user)
            ->postJson('/api/v1/email/verify/resend')
            ->assertStatus(202);

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    #[Test]
    public function a_browser_following_the_real_link_lands_back_in_the_portal_verified(): void
    {
        Notification::fake();

        [, $user] = $this->unverifiedAccount();

        $this->actingAs($user)->postJson('/api/v1/email/verify/resend')->assertStatus(202);

        $link = null;

        Notification::assertSentTo(
            $user,
            QueuedVerifyEmail::class,
            function (QueuedVerifyEmail $notification) use ($user, &$link): bool {
                $link = $notification->toMail($user)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($link);

        $portal = rtrim((string) config('app.frontend_url'), '/');
        $path = (string) parse_url($link, PHP_URL_PATH).'?'.(string) parse_url($link, PHP_URL_QUERY);

        // A person, in a browser, asking for a page.
        $this->get($path)->assertRedirect($portal.'/verify-email?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Clicking it again is not an error: the same link, the same landing,
        // and the portal says the address was already confirmed.
        $this->get($path)->assertRedirect($portal.'/verify-email?status=already_verified');

        // Now the money surfaces answer.
        $this->actingAs($user->fresh())->getJson('/api/v1/invoices')->assertOk();
    }

    #[Test]
    public function an_api_client_asking_for_json_still_gets_json(): void
    {
        Notification::fake();

        [, $user] = $this->unverifiedAccount();
        $this->actingAs($user)->postJson('/api/v1/email/verify/resend')->assertStatus(202);

        $link = null;
        Notification::assertSentTo(
            $user,
            QueuedVerifyEmail::class,
            function (QueuedVerifyEmail $notification) use ($user, &$link): bool {
                $link = $notification->toMail($user)->actionUrl;

                return true;
            },
        );

        $path = (string) parse_url((string) $link, PHP_URL_PATH).'?'.(string) parse_url((string) $link, PHP_URL_QUERY);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.already_verified', false);
    }

    #[Test]
    public function a_tampered_link_verifies_nothing_and_says_so_in_the_portal(): void
    {
        [, $user] = $this->unverifiedAccount();

        $portal = rtrim((string) config('app.frontend_url'), '/');

        /*
         * Unsigned, which is what an expired link and an edited link both look
         * like by the time they reach us. The browser is handed back to the
         * portal, which offers a new link; an API client still gets its 403.
         */
        $this->get('/api/v1/email/verify/'.$user->id.'/'.sha1((string) $user->email))
            ->assertRedirect($portal.'/verify-email?status=expired');

        $this->getJson('/api/v1/email/verify/'.$user->id.'/'.sha1((string) $user->email))
            ->assertStatus(403);

        // Correctly signed, and for an address that is not this user's: the
        // hash is what binds a link to an address, so the link is refused.
        $signed = URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('somebody.else@example.com'),
        ]);

        $path = (string) parse_url($signed, PHP_URL_PATH).'?'.(string) parse_url($signed, PHP_URL_QUERY);

        $this->get($path)->assertRedirect($portal.'/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
