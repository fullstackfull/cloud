<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedVerifyEmail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Email verification is only enforced if User implements the MustVerifyEmail
 * *contract*. The trait that Authenticatable already supplies gives the model
 * every verification method, but every framework enforcement point — the
 * listener that mails the link on Registered, and the `verified` middleware
 * that guards /api/admin — is an `instanceof` check against the interface.
 *
 * Without the interface the API answers registration with
 * `meta.email_verification_required: true`, mails nothing, and lets an
 * unverified account through `verified`. These tests fail on that model.
 */
final class EmailVerificationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function the_user_model_implements_the_contract_the_framework_enforces(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, User::factory()->unverified()->create());
    }

    #[Test]
    public function registration_actually_sends_the_verification_mail_it_promises(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.register'), [
            'name' => 'Amal Al-Sabah',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('meta.email_verification_required', true);

        Notification::assertSentTo(User::query()->sole(), QueuedVerifyEmail::class);
    }

    #[Test]
    public function the_link_in_that_mail_verifies_the_address(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.register'), [
            'name' => 'Amal Al-Sabah',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ])->assertAccepted();

        $user = User::query()->sole();
        $url = null;

        Notification::assertSentTo(
            $user,
            QueuedVerifyEmail::class,
            function (QueuedVerifyEmail $notification) use ($user, &$url): bool {
                // The URL the customer actually receives, not one the test builds.
                $url = $notification->toMail($user)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($url);
        $this->getJson($url)->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function the_verified_middleware_refuses_an_unverified_user(): void
    {
        // Exactly the middleware stack guarding the /api/admin group.
        Route::middleware(['auth:sanctum', 'verified'])
            ->get('/api/v1/__verified_probe', static fn (): array => ['reached' => true]);

        $this->actingAs(User::factory()->unverified()->create())
            ->getJson('/api/v1/__verified_probe')
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/__verified_probe')
            ->assertOk();
    }
}
