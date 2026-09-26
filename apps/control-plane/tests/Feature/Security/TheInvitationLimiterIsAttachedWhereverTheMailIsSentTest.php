<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Lynomia\Http\Middleware\ResolveActingCustomer;
use Lynomia\Http\Middleware\ThrottleAfterAccountResolution;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Team\TeamApiTestCase;

/**
 * The invitation limiter is attached to every road that posts an invitation,
 * and it runs where the account it is keyed on already exists.
 *
 * TheInvitationLimiterCannotBeRotatedByAHeaderTest pins the limiter's KEY by
 * calling its closure directly, with the acting account already set. That is
 * the right way to test a key and it cannot see two other things, both of
 * which were open:
 *
 * - **Attachment.** Deleting `throttle:team-invitations` from both routes left
 *   the whole suite green: the business group applies `throttle:api`, so a
 *   route that lost its own limiter was still "throttled" and still passed
 *   EveryAuthenticatedRouteIsThrottledTest. The gap is between throttled and
 *   throttled by THIS limiter, and the outcome behind it is the one F-17 was
 *   filed for — an ordinary customer mailing any address at the rate they can
 *   POST, from the platform's sending domain.
 *
 * - **Order.** The router sorts every stack against Laravel's middleware
 *   priority list. `ThrottleRequests` is on it and `ResolveActingCustomer` is
 *   not, so a plain `throttle:team-invitations` declared after `customer` ran
 *   BEFORE it, the closure found no account, and fell back to the user: one
 *   budget per administrator, not per account. The key test could not see
 *   this because it sets the account itself, and no HTTP test asserted the
 *   invitation budget at all.
 *
 * **Which routes are "roads that post an invitation" is derived, not listed.**
 * A route is one when its controller method uses InvitationMailer — through a
 * property of that type, a parameter of that type, or the class by name. A
 * list of two route names would say nothing about the third road added next
 * to them; this rule does. The two names below are a floor under the
 * derivation, not the rule: if a refactor moves the send somewhere the scan
 * cannot see, the rule must fail rather than pass by checking nothing.
 *
 * **What this does not cover:** a send reached indirectly — a helper method,
 * a service, a job, a listener or a console command between the route and the
 * mailer — or an InvitationMail posted without InvitationMailer; closure
 * routes; and the size of the budget, which is configuration. The floor turns
 * the first of those into a failure for the two roads that exist today, not
 * for a new one.
 *
 * **Why a test and not a check at boot.** Refusing to boot when a road that
 * posts an invitation lacks this limiter would make the mistake undeployable
 * rather than detected. It is not done, for three reasons. It would read
 * the same route table this class reads, so it could see nothing this class
 * cannot. It would move the failure from CI, before merge, to the
 * application's boot, where one wrong route stops every route answering. And
 * it would walk every route's middleware on every boot. A shape that does see
 * further is a check inside InvitationMailer::send that the request being
 * served ran the limiter: that would catch a send reached through a helper, a
 * service or a listener, but it ties a mailer to the HTTP layer, fails at the
 * moment a customer sends, and has no route to ask about for a send made
 * outside a request. The other half of the same question — a limiter
 * registered and attached to no route — is not checked anywhere.
 */
final class TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest extends TeamApiTestCase
{
    private const string LIMITER = 'team-invitations';

    /**
     * Roads the derivation must find. A floor, not the rule.
     */
    private const array KNOWN_ROADS = [
        'api.v1.team.invitations.create',
        'api.v1.team.invitations.resend',
    ];

    /**
     * Middleware that can run a named limiter. The first is the one the
     * invitation routes must use; the framework's is here so that a plain
     * `throttle:team-invitations` is recognised as attached — and then
     * refused by the ordering test, which is the precise reason it is wrong.
     */
    private const array LIMITER_MIDDLEWARE = [
        ThrottleAfterAccountResolution::class,
        ThrottleRequests::class,
    ];

    #[Test]
    public function every_route_that_posts_an_invitation_runs_the_invitation_limiter(): void
    {
        $roads = $this->roadsThatPostAnInvitation();

        foreach (self::KNOWN_ROADS as $name) {
            $this->assertArrayHasKey($name, $roads, sprintf(
                'The scan for routes that use %s did not find %s. The rule below would then pass by '
                .'checking fewer roads than exist; teach the scan where the send moved to.',
                InvitationMailer::class,
                $name,
            ));
        }

        $unbounded = [];

        foreach ($roads as $name => $route) {
            if ($this->positionOfTheInvitationLimiter($route) === null) {
                $unbounded[] = sprintf('%s %s  (%s)', $route->methods()[0], $route->uri(), $name);
            }
        }

        $this->assertSame([], $unbounded, implode("\n", [
            'These routes post an invitation without the '.self::LIMITER.' limiter. `throttle:api` from the '
            .'group does not count: it is per user per minute, and the budget that protects the recipients is '
            .'per account per hour.',
            ...$unbounded,
        ]));
    }

    #[Test]
    public function the_invitation_limiter_runs_after_the_account_it_is_keyed_on_is_resolved(): void
    {
        foreach ($this->roadsThatPostAnInvitation() as $name => $route) {
            $stack = $this->stackAsTheRouterRunsIt($route);
            $resolver = array_search(ResolveActingCustomer::class, $stack, true);
            $limiter = $this->positionOfTheInvitationLimiter($route);

            $this->assertIsInt($resolver, "{$name} does not resolve the acting customer at all.");
            $this->assertIsInt($limiter, "{$name} does not run the invitation limiter.");

            $this->assertLessThan($limiter, $resolver, sprintf(
                'On %s the invitation limiter runs before ResolveActingCustomer, so it keys on the user and '
                .'every administrator of the account gets a budget of their own. The priority sort moves any '
                ."ThrottleRequests there; attach it with %s instead.\n\nThe stack as the router runs it:\n  %s",
                $name,
                ThrottleAfterAccountResolution::class,
                implode("\n  ", array_map(static fn ($m): string => is_string($m) ? $m : get_debug_type($m), $stack)),
            ));
        }
    }

    /**
     * The outcome itself, over the real stack.
     *
     * Two administrators of one account share a budget, the budget binds on
     * both roads, and a header full of junk does not buy a fresh one. Every
     * request starts without an acting customer (startAFreshRequest), because
     * that is what a real request starts with.
     */
    #[Test]
    public function an_account_that_has_spent_its_budget_is_refused_on_both_roads_whatever_it_sends(): void
    {
        Mail::fake();
        config(['security.rate_limits.team_invitations.attempts' => 2]);

        [$customer, $owner] = $this->accountWithOwner();
        $colleague = $this->memberOf($customer, CustomerRole::Administrator);
        [$elsewhere, $otherOwner] = $this->accountWithOwner();

        $this->invite($owner, 'first@example.test')->assertCreated();
        $this->invite($colleague, 'second@example.test')->assertCreated();

        /** @var CustomerInvitation $offer */
        $offer = CustomerInvitation::query()->where('email', 'first@example.test')->firstOrFail();

        $this->resend($owner, $offer, 'junk-1')->assertTooManyRequests();
        $this->invite($colleague, 'third@example.test', 'NOT-A-ULID')->assertTooManyRequests();
        $this->invite($owner, 'fourth@example.test', strtoupper((string) $customer->getKey()))->assertTooManyRequests();

        // Separating accounts is still the point: another account's budget is
        // untouched by this one having spent its own.
        $this->invite($otherOwner, 'fifth@example.test', null, $elsewhere)->assertCreated();

        Mail::assertQueuedCount(3);
        Mail::assertQueued(InvitationMail::class, static fn (InvitationMail $mail): bool => $mail->hasTo('fifth@example.test'));
    }

    private function invite(User $user, string $email, ?string $header = null, ?Customer $for = null): TestResponse
    {
        $this->startAFreshRequest();

        return $this->actingAs($user)
            ->withHeaders($header === null ? ($for === null ? [] : $this->actingFor($for)) : ['X-Lynomia-Customer' => $header])
            ->postJson('/api/v1/team/invitations', ['email' => $email, 'role' => CustomerRole::Member->value]);
    }

    private function resend(User $user, CustomerInvitation $offer, string $header): TestResponse
    {
        $this->startAFreshRequest();

        return $this->actingAs($user)
            ->withHeaders(['X-Lynomia-Customer' => $header])
            ->postJson("/api/v1/team/invitations/{$offer->getKey()}/resend");
    }

    /**
     * What a real request starts with: no acting customer yet.
     *
     * Forgetting the scoped instance is not enough on its own. A route builds
     * its controller while gathering middleware and keeps it, and
     * TeamController holds the ActingCustomer it was built with — so the
     * route's controller is flushed too, or the next request would authorise
     * against the previous one's account.
     */
    private function startAFreshRequest(): void
    {
        $this->app->forgetScopedInstances();

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
    }

    /**
     * @return array<string, RouteDefinition>
     */
    private function roadsThatPostAnInvitation(): array
    {
        $roads = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            if ($this->usesTheInvitationMailer(new ReflectionMethod($class, $method))) {
                $roads[$route->getName() ?? $route->methods()[0].' '.$route->uri()] = $route;
            }
        }

        return $roads;
    }

    private function usesTheInvitationMailer(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ($this->isTheMailer($parameter->getType())) {
                return true;
            }
        }

        $file = $method->getFileName();

        if ($file === false) {
            return false;
        }

        $body = implode('', array_slice(
            (array) file($file),
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1,
        ));

        if (str_contains($body, class_basename(InvitationMailer::class))) {
            return true;
        }

        foreach ((new ReflectionClass($method->class))->getProperties() as $property) {
            if ($this->isTheMailer($property->getType()) && str_contains($body, '$this->'.$property->getName().'->')) {
                return true;
            }
        }

        return false;
    }

    private function isTheMailer(mixed $type): bool
    {
        return $type instanceof ReflectionNamedType && is_a($type->getName(), InvitationMailer::class, true);
    }

    /**
     * @return list<mixed>
     */
    private function stackAsTheRouterRunsIt(RouteDefinition $route): array
    {
        // gatherRouteMiddleware() is what Router::runRouteWithinStack() runs:
        // aliases and groups expanded, then sorted by priority. The declared
        // order in the route file is not the order that executes.
        return array_values(app(Router::class)->gatherRouteMiddleware($route));
    }

    private function positionOfTheInvitationLimiter(RouteDefinition $route): ?int
    {
        foreach ($this->stackAsTheRouterRunsIt($route) as $index => $middleware) {
            if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                continue;
            }

            [$class, $parameters] = explode(':', $middleware, 2);

            if (explode(',', $parameters)[0] !== self::LIMITER) {
                continue;
            }

            foreach (self::LIMITER_MIDDLEWARE as $runner) {
                if (is_a($class, $runner, true)) {
                    return $index;
                }
            }
        }

        return null;
    }
}
