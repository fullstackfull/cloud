<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
