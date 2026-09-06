<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Revoking a session must actually get the device out.
 *
 * A remember-me cookie is a standalone credential — `id|remember_token|
 * password-hash` — that SessionGuard re-authenticates from and mints a fresh
 * session for on the next request. It is not a `sessions` row, so deleting
 * rows leaves a stolen recaller cookie fully working: the customer performs
 * the remediation the product offers and stays compromised. Rotating
 * `remember_token` is what evicts it.
 */
final class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function signing_out_other_sessions_evicts_a_remember_me_device(): void
    {
        [$user, $login] = $this->userRememberedOnThisDevice();
        $stolen = $this->recallerFrom($login->headers->getCookies());
        $tokenBefore = $user->fresh()->remember_token;

        $revocation = $this->withCredentials()
            ->withCookie($stolen['name'], $stolen['value'])
            ->deleteJson(route('api.v1.me.sessions.destroy_others'))
            ->assertNoContent();

        $this->assertNotSame(
            $tokenBefore,
            $user->fresh()->remember_token,
            'remember_token must be rotated, or the revocation does not evict remembered devices.',
        );

        // The attacker's device: no session of its own, only the stolen cookie.
        $this->assertRecallerIsDead($stolen);

        // The customer's own device keeps its persistent login, under the new
        // token — it is re-issued on the revocation response.
        $reissued = $this->recallerFrom($revocation->headers->getCookies());
        $this->assertNotSame($stolen['value'], $reissued['value']);
        $this->assertRecallerStillWorks($reissued);
    }

    #[Test]
    public function revoking_a_single_session_evicts_a_remember_me_device(): void
    {
        [$user, $login] = $this->userRememberedOnThisDevice();
        $stolen = $this->recallerFrom($login->headers->getCookies());
        $tokenBefore = $user->fresh()->remember_token;

        // A session row to address. The intruder's remembered device is not one
        // of these rows, which is precisely the problem.
        DB::table('sessions')->insert([
            'id' => 'intruder-session-id',
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Some browser the customer does not recognise',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->withCredentials()
            ->withCookie($stolen['name'], $stolen['value'])
            ->deleteJson(route('api.v1.me.sessions.destroy', hash('sha256', 'intruder-session-id')))
            ->assertNoContent();

        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);
        $this->assertRecallerIsDead($stolen);
    }

    /**
     * @return array{0: User, 1: TestResponse}
     */
    private function userRememberedOnThisDevice(): array
    {
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $login = $this->postJson(route('api.v1.login'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'remember' => true,
        ])->assertOk();

        return [$user, $login];
    }

    /**
     * The recaller as a browser holds it: name plus the plaintext value.
     *
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

    /**
     * @param  array{name: string, value: string}  $recaller
     */
    private function assertRecallerIsDead(array $recaller): void
    {
        $this->freshClientWith($recaller)->assertUnauthorized();
    }

    /**
     * @param  array{name: string, value: string}  $recaller
     */
    private function assertRecallerStillWorks(array $recaller): void
    {
        $this->freshClientWith($recaller)
            ->assertOk()
            ->assertJsonPath('data.email', 'amal@example.com');
    }

    /**
     * A device holding nothing but the cookie: every session row is gone.
     *
     * @param  array{name: string, value: string}  $recaller
     */
    private function freshClientWith(array $recaller): TestResponse
    {
        DB::table('sessions')->delete();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withCookie($recaller['name'], $recaller['value'])
            ->getJson(route('api.v1.me'));
    }
}
