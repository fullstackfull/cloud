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
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Tests\Feature\Security\Fixtures\SpellingsOfTheInvitationMailer;
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
 *   Security, Team and Identity green, and those hold every test that names
 *   these routes. The business group applies `throttle:api`, so a route that
 *   lost its own limiter was still "throttled" and still passed
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
 * A route is one when its controller method uses InvitationMailer in any of
 * these ways:
 *
 * - a parameter declared with that type — alone, nullable, or as a member of
 *   a union or an intersection;
 * - the class by name, in code — resolved the way PHP resolves it: imported,
 *   aliased (`use … as Postman`), qualified or fully qualified — or written
 *   out in a string, in any letter case;
 * - a property of the controller declared with that type, in the same sense
 *   as a parameter, and read with PHP's property syntax: `->mailer` or
 *   `?->mailer`, on `$this` or on anything else, or `::$mailer`, with any
 *   whitespace, line breaks or comments between the parts, and whatever is
 *   done with it next — a call on the same line or the next, a copy into a
 *   local, an argument, a closure. A property whose name is an expression
 *   (`->{…}`, `->$name`, `::$$name`) cannot be named without running the
 *   code, so in a controller that has such a property it counts as that
 *   property.
 *
 * The body is read as PHP tokens; only the search for the class's name in a
 * string reads it as text.
 * the_scan_recognises_every_way_of_using_the_mailer_it_claims_to holds the
 * scan to this list with a fixture that uses the mailer each of these ways —
 * bar a qualified or fully qualified class name, which Pint rewrites into the
 * import in a file that has one — and two near misses it must not count.
 *
 * A list of two route names would say nothing about the third road added next
 * to them; this rule does. The two names below are a floor under the
 * derivation, not the rule: if a refactor moves the send somewhere the scan
 * cannot see, the rule must fail rather than pass by checking nothing.
 *
 * **What this does not cover:** a send reached indirectly — a helper method
 * (`__get` included), a service, a job, a listener or a console command
 * between the route and the mailer — or an InvitationMail posted without
 * InvitationMailer; a mailer property read other than by property syntax —
 * through reflection, `get_object_vars()` or an array cast of the controller
 * — or the mailer taken from the container by a key that is not its class's
 * name; a parameter or property with no declared type, or typed `object` or
 * `mixed`; closure routes; and the size of the budget, which is
 * configuration. The floor turns the first of those into a failure for the
 * two roads that exist today, not for a new one.
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

    /**
     * Presence in the stack, not execution: a runner that handed the request
     * on without throttling would pass this. The HTTP case below is what
     * proves the limiter runs.
     */
    #[Test]
    public function every_route_that_posts_an_invitation_carries_the_invitation_limiter(): void
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
     * The scan reaches as far as this class's docblock says it does.
     *
     * Every other test here trusts roadsThatPostAnInvitation(), so a scan
     * that missed a spelling would leave a third road unchecked while every
     * test stayed green — which is how a controller method that wrote
     * `$this->mailer` on one line and `->send()` on the next went unseen.
     * Each method of the fixture uses the mailer one way, or not at all, and
     * the scan must give exactly this answer for every one of them.
     */
    #[Test]
    public function the_scan_recognises_every_way_of_using_the_mailer_it_claims_to(): void
    {
        $expected = [
            'theOrdinarySpelling' => true,
            'theCallOnTheNextLine' => true,
            'throughALocal' => true,
            'nullsafeAfterTheProperty' => true,
            'nullsafeBeforeTheProperty' => true,
            'throughAnotherHandleOnTheController' => true,
            'insideAClosure' => true,
            'aStaticProperty' => true,
            'aUnionTypedProperty' => true,
            'anIntersectionTypedProperty' => true,
            'aPropertyNamedByALiteral' => true,
            'aPropertyNamedByAVariable' => true,
            'aStaticPropertyNamedByAVariable' => true,
            'theClassByAnAlias' => true,
            'theClassByItsImportedName' => true,
            'theClassInAString' => true,
            'theClassInAStringInAnotherCase' => true,
            'aParameter' => true,
            'aNullableParameter' => true,
            'aUnionParameter' => true,
            'anIntersectionParameter' => true,
            'anotherPropertyWhoseNameStartsTheSame' => false,
            'nothingToDoWithMail' => false,
        ];

        $answered = [];

        foreach ((new ReflectionClass(SpellingsOfTheInvitationMailer::class))->getMethods() as $method) {
            if (! $method->isConstructor()) {
                $answered[$method->getName()] = $this->usesTheInvitationMailer($method);
            }
        }

        $wrong = array_keys(array_diff_assoc($answered, $expected) + array_diff_key($expected, $answered));

        $this->assertSame([], $wrong, sprintf(
            "The scan for %s answered these fixture methods wrongly, or the fixture and this list disagree:\n  %s",
            InvitationMailer::class,
            implode("\n  ", array_map(
                static fn (string $name): string => sprintf('%s: expected %s, got %s', $name, var_export($expected[$name] ?? null, true), var_export($answered[$name] ?? null, true)),
                $wrong,
            )),
        ));
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

    /**
     * Whether a controller method uses InvitationMailer, in the ways the class
     * docblock lists.
     *
     * The body is read as PHP tokens rather than as text, so whitespace, line
     * breaks and comments between `$this`, the operator and the name change
     * nothing, and a name is resolved against the file's own imports the way
     * PHP resolves it.
     */
    private function usesTheInvitationMailer(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ($this->isDeclaredAsTheMailer($parameter->getType())) {
                return true;
            }
        }

        $file = $method->getFileName();

        if ($file === false) {
            return false;
        }

        $first = (int) $method->getStartLine();
        $last = (int) $method->getEndLine();

        $text = implode('', array_slice((array) file($file), $first - 1, $last - $first + 1));

        // Class names are case-insensitive, in a string as much as in code.
        if (stripos($text, class_basename(InvitationMailer::class)) !== false) {
            return true;
        }

        $tokens = $this->significantTokensOf($file);
        [$namespace, $imports] = $this->namesInScopeAt($tokens, $first);

        $properties = [];

        foreach ((new ReflectionClass($method->class))->getProperties() as $property) {
            if ($this->isDeclaredAsTheMailer($property->getType())) {
                $properties[] = $property->getName();
            }
        }

        $body = array_values(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => $token->line >= $first && $token->line <= $last,
        ));

        foreach ($body as $index => $token) {
            $next = $body[$index + 1] ?? null;

            if ($next !== null && $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                // `->mailer`, or `->{…}` / `->$name`: a name nobody can read
                // without running the code, so any of them could be the mailer.
                if ($next->is(T_STRING) ? in_array($next->text, $properties, true) : ($properties !== [] && ($next->text === '{' || $next->is(T_VARIABLE)))) {
                    return true;
                }
            }

            if ($next !== null && $token->is(T_DOUBLE_COLON)) {
                // `::$mailer`, or `::$$name` / `::${…}`, for the same reason.
                if ($next->is(T_VARIABLE) ? in_array(substr($next->text, 1), $properties, true) : ($properties !== [] && $next->text === '$')) {
                    return true;
                }
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])
                && is_a($this->resolve($token, $namespace, $imports), InvitationMailer::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declared with that type: alone, nullable, or as one member of a union
     * or an intersection. A parameter or property with no type, or typed
     * `object` or `mixed`, is not — see the class docblock.
     */
    private function isDeclaredAsTheMailer(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->isDeclaredAsTheMailer($member)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_a($type->getName(), InvitationMailer::class, true);
    }

    /**
     * @return list<PhpToken>
     */
    private function significantTokensOf(string $file): array
    {
        return array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }

    /**
     * The namespace and the class imports in force at a line, as PHP compiles
     * them: `use` statements at namespace level only (not a trait's `use` in a
     * class body, nor a closure's), aliases and group imports included,
     * `use function` and `use const` left out, and a new namespace starting
     * with none.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: string, 1: array<string, string>}
     */
    private function namesInScopeAt(array $tokens, int $line): array
    {
        $namespace = '';
        $imports = [];
        $braces = [];
        $namespaceOpening = false;

        foreach ($tokens as $index => $token) {
            if ($token->line >= $line) {
                break;
            }

            if ($token->is(T_NAMESPACE)) {
                $name = $tokens[$index + 1] ?? null;
                $namespace = $name !== null && $name->is([T_STRING, T_NAME_QUALIFIED]) ? $name->text : '';
                $imports = [];
                $namespaceOpening = true;
            } elseif ($token->text === ';') {
                $namespaceOpening = false;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braces[] = $namespaceOpening;
                $namespaceOpening = false;
            } elseif ($token->text === '}') {
                array_pop($braces);
            } elseif ($token->is(T_USE) && ! in_array(false, $braces, true) && ($tokens[$index - 1] ?? null)?->text !== ')') {
                $statement = [];

                for ($at = $index + 1; isset($tokens[$at]) && $tokens[$at]->text !== ';'; $at++) {
                    $statement[] = $tokens[$at];
                }

                $imports = [...$imports, ...$this->importsIn($statement)];
            }
        }

        return [$namespace, $imports];
    }

    /**
     * @param  list<PhpToken>  $statement  what follows `use`, up to the `;`
     * @return array<string, string> lower-cased alias => class
     */
    private function importsIn(array $statement): array
    {
        if ($statement === [] || $statement[0]->is([T_FUNCTION, T_CONST])) {
            return [];
        }

        $prefix = '';

        foreach ($statement as $index => $token) {
            if ($token->text === '{') {
                $prefix = implode('', array_map(static fn (PhpToken $part): string => $part->text, array_slice($statement, 0, $index)));
                $statement = array_slice($statement, $index + 1);

                break;
            }
        }

        $imports = [];
        $item = [];

        foreach ([...$statement, null] as $token) {
            if ($token === null || $token->text === ',' || $token->text === '}') {
                if ($item !== [] && ! $item[0]->is([T_FUNCTION, T_CONST])) {
                    $class = ltrim($prefix.$item[0]->text, '\\');
                    $alias = isset($item[2]) && $item[1]->is(T_AS) ? $item[2]->text : class_basename($class);
                    $imports[strtolower($alias)] = $class;
                }

                $item = [];

                continue;
            }

            $item[] = $token;
        }

        return $imports;
    }

    /**
     * A name as PHP resolves a class name: fully qualified as written,
     * `namespace\…` against the namespace, otherwise through an import of its
     * first segment, and failing that against the namespace.
     *
     * @param  array<string, string>  $imports
     */
    private function resolve(PhpToken $name, string $namespace, array $imports): string
    {
        if ($name->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name->text, '\\');
        }

        $relative = $name->is(T_NAME_RELATIVE) ? substr($name->text, strlen('namespace\\')) : $name->text;
        $first = strtolower(explode('\\', $relative, 2)[0]);

        if (! $name->is(T_NAME_RELATIVE) && isset($imports[$first])) {
            return $imports[$first].substr($relative, strlen($first));
        }

        return ltrim($namespace.'\\'.$relative, '\\');
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
