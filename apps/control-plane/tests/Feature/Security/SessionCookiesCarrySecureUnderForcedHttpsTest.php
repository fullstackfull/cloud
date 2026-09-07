<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/production-checklist.md offers exactly one line on transport security:
 *
 *     [ ] FORCE_HTTPS=true, so HSTS is sent and session cookies carry `Secure`.
 *
 * That was only half true. FORCE_HTTPS reached the HSTS header and nothing
 * else; the `Secure` attribute came from `SESSION_SECURE_COOKIE`, a separate
 * variable .env.example shipped as `false`, no Ansible template set and the
 * checklist never named. An operator who followed the checklist exactly got
 * HSTS plus a session cookie, an XSRF cookie and a five-year remember-me
 * cookie with no `Secure` flag — all attached in cleartext to the first
 * http:// request the browser makes before an HSTS entry is pinned, which the
 * following 301 cannot un-send.
 */
final class SessionCookiesCarrySecureUnderForcedHttpsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The derivation itself, read out of the config file with FORCE_HTTPS set
     * and SESSION_SECURE_COOKIE deliberately left at the value .env.example
     * ships: a stale `false` must not be able to switch `Secure` back off.
     */
    #[Test]
    public function force_https_derives_the_secure_cookie_flag(): void
    {
        $restore = [
            'FORCE_HTTPS' => getenv('FORCE_HTTPS'),
            'SESSION_SECURE_COOKIE' => getenv('SESSION_SECURE_COOKIE'),
        ];

        try {
            $this->putEnv('FORCE_HTTPS', 'false');
            $this->putEnv('SESSION_SECURE_COOKIE', false);
            $this->assertFalse((require base_path('config/session.php'))['secure']);

            $this->putEnv('FORCE_HTTPS', 'true');
            $this->putEnv('SESSION_SECURE_COOKIE', false);
            $this->assertTrue((require base_path('config/session.php'))['secure']);

            // The value .env.example ships, next to FORCE_HTTPS=true.
            $this->putEnv('SESSION_SECURE_COOKIE', 'false');
            $this->assertTrue(
                (require base_path('config/session.php'))['secure'],
                'SESSION_SECURE_COOKIE=false must not defeat FORCE_HTTPS=true.',
            );

            // And it can still turn the flag on by itself.
            $this->putEnv('FORCE_HTTPS', 'false');
            $this->putEnv('SESSION_SECURE_COOKIE', 'true');
            $this->assertTrue((require base_path('config/session.php'))['secure']);
        } finally {
            foreach ($restore as $key => $value) {
                $this->putEnv($key, $value === false ? false : $value);
            }
        }
    }

    /**
     * And end to end: with the flag on, every credential the login response
     * hands the browser carries `Secure` — the session cookie, the recaller
     * and the XSRF cookie. Sanctum's EnsureFrontendRequestsAreStateful
     * overwrites `http_only` and `same_site` at runtime and deliberately
     * leaves `secure` alone, so this asserts nothing downstream strips it.
     */
    #[Test]
    public function every_cookie_the_login_response_sets_is_secure(): void
    {
        config()->set('session.secure', true);
        config()->set('security.force_https', true);

        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make('correct-horse-9'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-9',
            'remember' => true,
        ]);

        $response->assertOk();

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $session = $cookies[config('session.cookie')] ?? null;
        $this->assertNotNull($session, 'The login response must set a session cookie.');

        $remember = null;
        foreach ($cookies as $name => $cookie) {
            if (str_starts_with($name, 'remember_web_')) {
                $remember = $cookie;
            }
        }
        $this->assertNotNull($remember, 'remember=true must set a recaller cookie.');

        $this->assertTrue($session->isSecure(), 'The session cookie must carry Secure.');
        $this->assertTrue($remember->isSecure(), 'The remember-me cookie must carry Secure.');
        $this->assertTrue($cookies['XSRF-TOKEN']->isSecure(), 'The XSRF cookie must carry Secure.');

        // Unchanged, and asserted here so a future change to one flag cannot
        // quietly drop the other two.
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
    }

    private function putEnv(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
