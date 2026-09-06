<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requests that produce responses at each interesting point in the
     * middleware stack: before authentication, inside validation, and from a
     * route that succeeds.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function apiResponses(): iterable
    {
        yield 'unauthenticated 401' => ['GET', '/api/v1/me', 401];
        yield 'validation 422' => ['POST', '/api/v1/login', 422];
        yield 'not found 404' => ['GET', '/api/v1/does-not-exist', 404];
    }

    #[Test]
    #[DataProvider('apiResponses')]
    public function every_api_response_carries_the_security_headers(string $method, string $uri, int $expectedStatus): void
    {
        $response = $this->json($method, $uri);

        $response->assertStatus($expectedStatus);

        /*
         * A 401 is the response an attacker sees most while probing, and it is
         * produced by authentication middleware that Laravel's priority ordering
         * runs *before* a group's appended middleware. Header middleware
         * registered on the group alone silently misses it, which is why this
         * case is asserted explicitly rather than assumed.
         */
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function api_responses_are_never_stored_by_a_shared_cache(): void
    {
        // Responses carry invoices, IP allocations and service details.
        $cacheControl = $this->getJson('/api/v1/me')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', (string) $cacheControl);
        $this->assertStringContainsString('private', (string) $cacheControl);
    }

    #[Test]
    public function hsts_is_absent_when_https_is_not_enforced_and_present_when_it_is(): void
    {
        config()->set('security.force_https', false);
        $this->assertNull($this->getJson('/api/v1/me')->headers->get('Strict-Transport-Security'));

        config()->set('security.force_https', true);
        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $this->getJson('/api/v1/me')->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function every_response_carries_a_correlation_id_header(): void
    {
        $this->getJson('/api/v1/me')->assertHeader('X-Request-Id');
    }

    #[Test]
    public function a_client_supplied_correlation_id_is_echoed_when_it_is_well_formed(): void
    {
        $this->withHeader('X-Request-Id', 'trace-abc-123')
            ->getJson('/api/v1/me')
            ->assertHeader('X-Request-Id', 'trace-abc-123');
    }

    #[Test]
    public function a_malicious_correlation_id_is_replaced_rather_than_echoed(): void
    {
        // Echoing this verbatim would let a caller forge log lines.
        $response = $this->withHeader('X-Request-Id', "abc\ninjected log line")
            ->getJson('/api/v1/me');

        $this->assertNotSame("abc\ninjected log line", $response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression('/\A[0-9A-Za-z]{26}\z/', (string) $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function an_over_long_correlation_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-Id', str_repeat('a', 500))
            ->getJson('/api/v1/me');

        $this->assertSame(26, strlen((string) $response->headers->get('X-Request-Id')));
    }

    #[Test]
    public function error_bodies_use_one_shape_across_every_failure_kind(): void
    {
        foreach ([['GET', '/api/v1/me'], ['POST', '/api/v1/login'], ['GET', '/api/v1/nope']] as [$method, $uri]) {
            $this->json($method, $uri)->assertJsonStructure([
                'error' => ['code', 'message', 'request_id'],
            ]);
        }
    }
}
