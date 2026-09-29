<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsTheMiddlewareARouteRuns;
use Tests\TestCase;

/**
 * The numeric throttles on the customer billing routes are keyed by a bucket
 * named for the thing they limit.
 *
 * The third argument of `throttle:N,M,prefix:` is the bucket: two routes with
 * the same prefix spend one counter, two with different prefixes never share.
 * The re-audit found the names crossed - cancelling a subscription counted in
 * `plan-quote:`, and paying an invoice from wallet credit counted in
 * `subscription-cancel:` - so a reader deciding which limits were shared, or
 * tightening one of them, would have been misled by the name.
 *
 * What this reads: for the three named routes below, the middleware the
 * router runs (ReadsTheMiddlewareARouteRuns — Router::gatherRouteMiddleware():
 * aliases resolved to classes with their parameters, `withoutMiddleware()`
 * exclusions removed), and in it the third parameter of every entry whose
 * class is ThrottleRequests or a subclass and whose first parameter is a
 * number. Nothing else; it does not measure a limit by sending requests. It
 * used to read Route::gatherMiddleware(), which kept a throttle that
 * `->withoutMiddleware(...)` had removed (the OB5-2 class, re-audit of round
 * four).
 */
final class EachBillingLimiterIsNamedForWhatItLimitsTest extends TestCase
{
    use ReadsTheMiddlewareARouteRuns;
    use RefreshDatabase;

    #[Test]
    public function each_money_or_lifecycle_route_on_the_billing_surface_counts_in_its_own_named_bucket(): void
    {
        $expected = [
            'api.v1.subscriptions.cancel' => 'subscription-cancel:',
            'api.v1.subscriptions.plan' => 'plan-change:',
            'api.v1.invoices.wallet_credit.pay' => 'wallet-credit:',
        ];

        $found = [];

        foreach ($expected as $name => $prefix) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' is not registered; this pin would be vacuous.');

            $prefixes = [];
            foreach ($this->parametersOf($route, ThrottleRequests::class) as $arguments) {
                if ($arguments !== [] && is_numeric($arguments[0]) && isset($arguments[2])) {
                    $prefixes[] = trim($arguments[2]);
                }
            }

            $found[$name] = $prefixes;
        }

        $this->assertSame(
            array_map(static fn (string $prefix): array => [$prefix], $expected),
            $found,
            'Each billing limiter must count in the bucket its name says, and only one.',
        );
    }
}
