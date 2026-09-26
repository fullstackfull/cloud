<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Lynomia\Modules\Shared\Domain\Contracts\HostResolver;
use Tests\Support\StaticHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Names the endpoint policy is asked about are answered from a table,
         * never by the system resolver: a fixture's hostname is not sent to
         * DNS, and a test that is about what a name resolves to says so. A
         * fresh table per test, so one test's answers are not another's.
         *
         * This covers what resolves through the HostResolver seam and nothing
         * else. Code that resolves names by calling PHP's own functions — the
         * WordPress site probe does — is outside it.
         */
        $this->app->instance(HostResolver::class, new StaticHostResolver);

        /*
         * The portals authenticate with Sanctum's SPA cookie session, which
         * Sanctum only enables for requests whose origin is a configured
         * stateful domain. Without an Origin header the test client looks like
         * a token API caller and no session exists, so session regeneration,
         * CSRF and cookie behaviour would go untested.
         */
        $this->withHeader('Origin', (string) config('app.url'));
    }
}
