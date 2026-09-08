<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An unauthenticated request answers 401, whatever it asked for.
 *
 * Laravel's Authenticate middleware redirects a guest to a route named `login`
 * unless the request says it expects JSON. This application is an API and has
 * no such route, so `route('login')` threw and every unauthenticated request
 * without an `Accept: application/json` header — a browser opening an endpoint
 * directly, a client library that forgets the header, a monitoring probe —
 * received 500 `server.error` reading "Route [login] not defined".
 *
 * Two things were wrong with that. The status was a lie: nothing had failed on
 * the server, the caller was simply not signed in. And it filled the error
 * tracker with a platform bug wearing the costume of an application error,
 * which is the kind of noise that makes a real 500 invisible.
 *
 * Found by the browser suite, on its first run, waiting for the API to come up.
 */
final class UnauthenticatedRequestsAnswerJsonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_request_that_asks_for_json_is_told_it_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    #[Test]
    public function a_request_that_asks_for_nothing_in_particular_is_told_the_same_thing(): void
    {
        // No Accept header at all: the shape a browser address bar, a curl
        // without flags, and half the HTTP client libraries in the world send.
        $this->get('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    #[Test]
    public function a_request_that_asks_for_html_is_told_the_same_thing(): void
    {
        // There is no HTML on this application to redirect to, so answering an
        // HTML request with a redirect would send it somewhere that does not
        // exist.
        $this->get('/api/v1/me', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    #[Test]
    public function the_operator_surface_answers_the_same_way(): void
    {
        $this->get('/api/admin/customers')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }
}
