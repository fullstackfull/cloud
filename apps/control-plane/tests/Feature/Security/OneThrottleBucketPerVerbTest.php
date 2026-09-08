<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every numeric rate limiter has a bucket of its own.
 *
 * `ThrottleRequests` builds its key as `$prefix . resolveRequestSignature()`,
 * and the signature of an authenticated request is the user id — nothing about
 * the route. So two routes carrying a bare `throttle:N,1` share one counter
 * per user, and the tighter of the two silently spends the looser one's
 * allowance.
 *
 * It is a subtle failure. Nothing is broken until a customer does several
 * ordinary things in a minute and the next one answers 429 for a reason no
 * client can see and no log explains — which is exactly how it was found:
 * a whole-life test that claimed a zone, wrote three records, edited one and
 * then could not delete the zone.
 *
 * The convention was already written down in routes/v1/dedicated.php. This is
 * the check that keeps it true.
 */
final class OneThrottleBucketPerVerbTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_route_carries_an_unprefixed_numeric_throttle(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $arguments = explode(',', substr($middleware, strlen('throttle:')));

                // `throttle:api` and friends name a limiter registered in the
                // service provider, which builds its own key. Only the numeric
                // form takes a prefix, and only the numeric form needs one.
                if (! is_numeric($arguments[0])) {
                    continue;
                }

                if (count($arguments) < 3 || trim($arguments[2]) === '') {
                    $offenders[] = sprintf('%s %s (%s)', $route->methods()[0], $route->uri(), $middleware);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These routes share one throttle counter per user with every other unprefixed numeric limiter:',
            ...$offenders,
        ]));
    }
}
