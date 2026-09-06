<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The auth limiter's key was built by casting raw, unvalidated input:
 *
 *     strtolower(trim((string) $request->input('email')))
 *
 * `email` arriving as an array made PHP raise "Array to string conversion",
 * which the framework's error handler turns into an ErrorException. The
 * closure runs inside ThrottleRequests::handleRequestUsingNamedLimiter, BEFORE
 * RateLimiter::attempt() — so every one of the four unauthenticated auth
 * endpoints had a request shape that always returned 500, was report()ed with
 * a stack trace into the log pipeline, and was never counted by any limiter.
 *
 * A limiter closure sees the request before validation does and must assume
 * the worst about every type it touches.
 */
final class NonStringEmailDoesNotBypassTheAuthLimiterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string}>
     */
    public static function authEndpoints(): iterable
    {
        yield 'login' => ['/api/v1/login'];
        yield 'register' => ['/api/v1/register'];
        yield 'forgot password' => ['/api/v1/password/forgot'];
        yield 'reset password' => ['/api/v1/password/reset'];
    }

    #[Test]
    #[DataProvider('authEndpoints')]
    public function a_non_string_email_is_rejected_by_validation_not_by_a_crash(string $uri): void
    {
        $response = $this->postJson($uri, [
            'email' => ['a@example.com'],
            'password' => 'irrelevant',
        ]);

        $this->assertNotSame(500, $response->status(), 'The limiter crashed on a malformed email.');
        $response->assertStatus(422);
    }

    #[Test]
    public function the_malformed_shape_is_counted_towards_the_throttle(): void
    {
        $seen = [];

        // Well above every per-identity and per-IP auth budget. If the shape
        // were still uncounted this loop would produce 500s and no 429 at all.
        for ($i = 0; $i < 60; $i++) {
            $seen[] = $this->postJson('/api/v1/login', [
                'email' => ['victim@example.com'],
                'password' => 'Summer2026!',
            ])->status();
        }

        $this->assertNotContains(500, $seen);
        $this->assertContains(429, $seen, 'A malformed email is still an uncounted, unthrottled request.');
    }
}
