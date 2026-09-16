<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The route table and the controllers agree about what exists.
 *
 * W5.9 §21 asks for the backend half of the dead-path hunt W5.7 did in the
 * portal: an unreachable endpoint, a method with no reachable route, a stale
 * contract. NoDeadMethodsTest already hunts application methods nothing calls;
 * this is the layer above, and it asks two questions the route table can
 * answer exactly.
 *
 * **A route naming a method that does not exist** is not dead code — it is a
 * 500 waiting for the first caller, and nothing before this noticed. Laravel
 * resolves the action at request time, so `route:list` prints it happily and
 * the suite stays green until somebody visits it.
 *
 * **A controller method with no route** is an endpoint that cannot be reached:
 * a handler somebody wrote, tested perhaps, and never wired. It reads as a
 * working feature and is not in the product.
 *
 * ## The allow-list
 *
 * §21 is explicit that future-prepared operator capabilities are not to be
 * deleted, so the one that exists is named here with the reason it exists —
 * and the reason is quoted from the code itself rather than invented.
 */
final class EveryControllerMethodIsReachableTest extends TestCase
{
    /**
     * Methods every Laravel controller may have without a route of its own.
     *
     * @var list<string>
     */
    private const FRAMEWORK = ['__construct', 'callAction', 'middleware', 'acting'];

    /**
     * Handlers deliberately built before the route that will carry them.
     *
     * @var array<string, string>
     */
    private const PREPARED = [
        'Lynomia\Modules\Monitoring\Http\Controllers\MetricsController::__invoke' => 'The Prometheus endpoint. Its own route file declares the intent and says why it is not '
            .'registered: "Not registered anywhere yet: routes/** belongs to the coordinator", and it is '
            .'deliberately outside the api/v1 and api/admin groups because a scraper is neither a browser '
            .'nor a customer token and must not share their rate limiter. Operator capability, prepared, '
            .'preserved per §21.',
    ];

    #[Test]
    public function no_route_names_a_controller_method_that_does_not_exist(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');

            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class)) {
                $broken[] = sprintf('%-46s names the class %s, which does not exist', $route->uri(), $class);

                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = sprintf(
                    '%-46s names %s::%s, which does not exist',
                    $route->uri(),
                    class_basename($class),
                    $method,
                );
            }
        }

        $this->assertSame([], $broken, sprintf(
            "%d route(s) name a handler that is not there:\n\n%s\n\n".
            'Laravel resolves the action at request time, so the route table prints these happily and '.
            "nothing fails until somebody visits one. Each is a 500 with a customer's name on it.\n",
            count($broken),
            implode("\n", $broken),
        ));
    }

    #[Test]
    public function no_controller_carries_a_handler_nothing_can_reach(): void
    {
        $routed = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');

            if (! is_string($action)) {
                continue;
            }

            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                $routed[$class][$method] = true;

                continue;
            }

            // A single-action controller, routed by class name.
            if (class_exists($action)) {
                $routed[$action]['__invoke'] = true;
            }
        }

        $unreachable = [];

        foreach ($this->controllerFiles() as $file) {
            $source = (string) file_get_contents($file);

            $namespace = preg_match('/^namespace ([^;]+);/m', $source, $m) === 1 ? $m[1] : null;

            if ($namespace === null) {
                continue;
            }

            $class = $namespace.'\\'.basename($file, '.php');

            // Comments stripped, for the same reason NoDeadMethodsTest strips
            // them: a method named only in a docblock is not called by it.
            $code = preg_replace('#//.*#', '', (string) preg_replace('#/\*.*?\*/#s', '', $source));

            preg_match_all('/public function ([a-zA-Z_]+)\(/', (string) $code, $found);

            foreach ($found[1] as $method) {
                if (in_array($method, self::FRAMEWORK, strict: true)) {
                    continue;
                }

                if (isset($routed[$class][$method])) {
                    continue;
                }

                if (array_key_exists("{$class}::{$method}", self::PREPARED)) {
                    continue;
                }

                $unreachable[] = sprintf('%s::%s', str_replace('Lynomia\Modules\\', '', $class), $method);
            }
        }

        $this->assertSame([], $unreachable, sprintf(
            "%d controller handler(s) have no route:\n\n  %s\n\n".
            'Each reads as a working endpoint and is not in the product. Delete it, wire it, or — if it '.
            'is an operator capability built ahead of its route — add it to PREPARED with the reason, '.
            "which §21 permits and silence does not.\n",
            count($unreachable),
            implode("\n  ", $unreachable),
        ));
    }

    /**
     * The allow-list cannot outlive what it excuses.
     */
    #[Test]
    public function every_prepared_handler_still_exists(): void
    {
        $gone = [];

        foreach (array_keys(self::PREPARED) as $handler) {
            [$class, $method] = explode('::', $handler, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                $gone[] = $handler;
            }
        }

        $this->assertSame([], $gone, sprintf(
            "These handlers are excused from needing a route and no longer exist:\n\n  %s\n\n".
            "An excuse for something that is gone is an excuse waiting to cover something else.\n",
            implode("\n  ", $gone),
        ));
    }

    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $files = glob(base_path('src/Modules/*/Http/Controllers/*.php'));

        $this->assertNotEmpty($files, 'Found no controllers, so this gate would pass on an empty reading.');

        return $files === false ? [] : $files;
    }
}
