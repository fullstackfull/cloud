<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No class a customer route can reach declares, in one of the five spellings
 * listed below, an error code the customer catalogue has no sentence for —
 * except a class reached only through a queued job that catches it, named in
 * EXCUSED_BEHIND_A_QUEUED_CATCH with the checks that excuse must still pass.
 *
 * ===========================================================================
 * WHY
 * ===========================================================================
 *
 * The renderer answers a DomainException with the catalogue's sentence for
 * its code. For a code with no entry it used to fall back to the exception's
 * own message — the engineer's, which may name a node, a job or a provider —
 * and the re-audit after round two could not establish whether any of the
 * uncatalogued codes (drift.*, infrastructure.*, monitoring.invalid_metric,
 * provisioning.adoption_*, repoint_*, retry_*, rbac.*) reached a customer
 * route. On `api/v1/*` that fallback is now the generic translated sentence
 * (bootstrap/app.php; ACustomerRouteNeverSpeaksTheEngineersSentenceTest), so
 * nothing leaks either way. This gate is the other half: a customer refusal
 * should carry a sentence that says what went wrong, so a class that declares
 * an uncatalogued code must not be reachable from a customer route at all.
 * When one becomes reachable, catalogue its codes (en and ar) or keep it off
 * the customer path.
 *
 * ===========================================================================
 * EXACTLY WHAT IT READS
 * ===========================================================================
 *
 * CODES. Every `.php` file under `src/`, comments removed, for five
 * spellings only (CODE_SPELLINGS), each a string literal of two or more
 * dot-separated lower-case words: the first argument of `->as('a.b')`;
 * `return 'a.b';`; `$errorCode = 'a.b'` or `$code = 'a.b'` (a property
 * default, a parameter default or an assignment); `->errorCode = 'a.b'`; and
 * the named argument `errorCode: 'a.b'`. A code built any other way —
 * concatenated, held in a constant or an enum, passed positionally to a
 * constructor or a method, assigned to a property or variable of another
 * name — is NOT read. The last two spellings also read codes that are never
 * thrown (a ProvisioningResult's `errorCode:`), which only makes it redder. A code is
 * uncatalogued when `lang/en/errors.php`, flattened on dots, has no key for
 * it. The class that declares it is the one declared in the file it was
 * found in.
 *
 * START. Every `Lynomia\...` class name written in `routes/api_v1.php` and
 * `routes/v1/*.php` that names a class under `src/`.
 *
 * EDGES, over-approximate on purpose (more edges can only make it redder).
 * From a class's file, comments removed first:
 *  - every `use Lynomia\...;` import whose short name (or alias) is then
 *    written in the code;
 *  - every `Lynomia\...` name written out in the code;
 *  - every class of the same namespace whose short name is written in it;
 *  - an interface to every class that implements it (a container binding
 *    is how a contract reaches its implementation);
 *  - an event to its listeners, read from `$listen` in
 *    `src/Providers/EventServiceProvider.php`, queued listeners included.
 * NOT read: a class resolved from a string (`app('x')`, a config value, a
 * name assembled at runtime), a listener registered anywhere else, a route
 * closure's body beyond the names it writes, and middleware registered in
 * `bootstrap/app.php`. An edge is a mention, not a call: a class reached here
 * may never actually run on a customer request, which is the direction this
 * gate is allowed to be wrong in.
 *
 * EXCUSES. A class in EXCUSED_BEHIND_A_QUEUED_CATCH is not an offence while
 * all of these hold, and each is checked here: its catcher implements
 * ShouldQueue; the catcher's source catches `Throwable` (and, for an
 * exception, the class by its short name); no file under `src/` calls the
 * catcher through `Catcher::dispatchSync(`, `Catcher::dispatchNow(`,
 * `dispatch_sync(new Catcher` or `(new Catcher(...))->handle(` (other ways
 * of running it inside the request are not read); and with
 * the catcher taken out of the graph, the walk no longer reaches the class —
 * so every path found runs through the queue. What is NOT checked is that the
 * catch's `try` spans every throw site inside the catcher; that is the
 * excuse's stated reason, read by a person.
 *
 * Measured at round three's base, with the five spellings: 277 codes read,
 * 38 uncatalogued, declared by 12 classes; the walk reaches 940 classes,
 * among them 3 of the 12 — HandlerNotRegisteredException,
 * ProvisioningFailedException and FakeProvisioningHandler — each only
 * through RunProvisioningJob and each excused. (With the first two
 * spellings alone it read 230 codes, 26 uncatalogued, 9 classes, none
 * reached; the verifier found the two exceptions it could not see.) The known
 * customer refusal CheckoutRejectedException is reached (through PlaceOrder),
 * which is what shows the walk walks.
 */
final class NoCustomerRouteReachesAnUncataloguedCodeTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * The spellings of a code this gate reads, each capturing a literal of two
     * or more dot-separated lower-case words. Nothing else is read.
     */
    private const array CODE_SPELLINGS = [
        // ->as('a.b')
        "/->as\\(\\s*'([a-z_]+(?:\\.[a-z_]+)+)'/",
        // return 'a.b';
        "/return\\s+'([a-z_]+(?:\\.[a-z_]+)+)'\\s*;/",
        // $errorCode = 'a.b' / $code = 'a.b': a property default, a
        // parameter default or an assignment to a variable of that name
        "/\\$(?:errorCode|code)\\s*=\\s*'([a-z_]+(?:\\.[a-z_]+)+)'/",
        // ->errorCode = 'a.b'
        "/->errorCode\\s*=\\s*'([a-z_]+(?:\\.[a-z_]+)+)'/",
        // errorCode: 'a.b' (a named argument)
        "/\\berrorCode:\\s*'([a-z_]+(?:\\.[a-z_]+)+)'/",
    ];

    /**
     * Classes the walk reaches only through a queued job that catches what
     * they raise, so nothing they raise reaches an HTTP response.
     *
     * RunProvisioningJob wraps `$handlers->get($job->kind)->execute($job)` in
     * one `try`: HandlerNotRegisteredException (the registry's `get`),
     * ProvisioningFailedException (a handler's classified failure) and every
     * other Throwable are caught there and recorded on the job row as a
     * ProvisioningResult. A customer route only ever DISPATCHES the job.
     * FakeProvisioningHandler's `fake.capacity` is a ProvisioningResult's
     * code, returned into that same `try`, never thrown.
     *
     * @var array<class-string, class-string> excused class => its catcher
     */
    private const array EXCUSED_BEHIND_A_QUEUED_CATCH = [
        'Lynomia\\Modules\\Provisioning\\Domain\\Exceptions\\HandlerNotRegisteredException' => self::PROVISIONING_WORKER,
        'Lynomia\\Modules\\Provisioning\\Domain\\Exceptions\\ProvisioningFailedException' => self::PROVISIONING_WORKER,
        'Lynomia\\Modules\\Provisioning\\Infrastructure\\Handlers\\FakeProvisioningHandler' => self::PROVISIONING_WORKER,
    ];

    private const string PROVISIONING_WORKER = 'Lynomia\\Modules\\Provisioning\\Application\\Jobs\\RunProvisioningJob';

    /** @var array<string, list<string>>|null */
    private static ?array $edges = null;

    /** @var array<string, string>|null fully-qualified class => file */
    private static ?array $classes = null;

    #[Test]
    public function no_class_a_customer_route_reaches_declares_an_uncatalogued_code(): void
    {
        $uncatalogued = self::uncataloguedCodesByClass();
        $reached = self::reachedFromCustomerRoutes();

        $offending = [];

        foreach ($uncatalogued as $class => $codes) {
            if (array_key_exists($class, $reached) && ! self::excused($class)) {
                $offending[] = sprintf(
                    '%s (%s), reached by: %s',
                    $class,
                    implode(', ', $codes),
                    implode(' <- ', self::pathTo($class, $reached)),
                );
            }
        }

        $this->assertSame(
            [],
            $offending,
            "A customer route can reach a class that raises a code the customer catalogue has no sentence for.\n"
            ."Catalogue the code in lang/en/errors.php and lang/ar/errors.php, or keep the class off the customer path.\n"
            .implode("\n", $offending),
        );
    }

    #[Test]
    public function every_excuse_still_holds_and_is_still_needed(): void
    {
        $classes = self::classes();
        $uncatalogued = self::uncataloguedCodesByClass();
        $reached = self::reachedFromCustomerRoutes();

        foreach (self::EXCUSED_BEHIND_A_QUEUED_CATCH as $class => $catcher) {
            // Still needed: an excuse for a class that is catalogued or
            // unreached is one nobody will notice going stale.
            $this->assertArrayHasKey($class, $uncatalogued, "{$class} is excused but declares no uncatalogued code; remove the excuse.");
            $this->assertArrayHasKey($class, $reached, "{$class} is excused but the walk no longer reaches it; remove the excuse.");

            $this->assertTrue(self::excused($class), "The excuse for {$class} behind {$catcher} no longer holds: ".self::whyNotExcused($class));
        }

        $this->assertArrayHasKey(self::PROVISIONING_WORKER, $classes);
    }

    #[Test]
    public function the_scan_and_the_walk_are_not_empty(): void
    {
        // Floors, so the gate above cannot pass by reading nothing.
        $this->assertGreaterThan(200, count(self::codes()), 'The code scan found suspiciously few codes; the spellings it reads have drifted.');

        $reached = self::reachedFromCustomerRoutes();
        $this->assertGreaterThan(500, count($reached), 'The walk from the customer routes reached suspiciously little.');

        // A refusal every customer can meet at checkout is reached, and one
        // only an operator can meet is not: the walk distinguishes the two.
        $this->assertArrayHasKey('Lynomia\\Modules\\Orders\\Domain\\Exceptions\\CheckoutRejectedException', $reached);
        $this->assertArrayNotHasKey('Lynomia\\Modules\\Provisioning\\Application\\Actions\\RetryProvisioningJob', $reached);
    }

    private static function excused(string $class): bool
    {
        return self::whyNotExcused($class) === null;
    }

    /**
     * Why the class's excuse does not hold, or null when it does (and when
     * it has none, a reason saying so).
     */
    private static function whyNotExcused(string $class): ?string
    {
        $catcher = self::EXCUSED_BEHIND_A_QUEUED_CATCH[$class] ?? null;

        if ($catcher === null) {
            return 'it has no excuse';
        }

        $classes = self::classes();

        if (! isset($classes[$catcher])) {
            return "its catcher {$catcher} does not exist";
        }

        $source = self::withoutComments((string) file_get_contents($classes[$catcher]));

        if (preg_match('/\\bimplements\\b[^{]*\\bShouldQueue\\b/', $source) !== 1) {
            return 'its catcher is not queued';
        }

        if (preg_match('/catch\\s*\\(\\s*\\\\?Throwable\\s+\\$/', $source) !== 1) {
            return 'its catcher does not catch Throwable';
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);

        if (str_ends_with($short, 'Exception') && preg_match('/catch\\s*\\([^)]*\\b'.preg_quote($short, '/').'\\b/', $source) !== 1) {
            return "its catcher does not catch {$short}";
        }

        $catcherShort = substr($catcher, (int) strrpos($catcher, '\\') + 1);

        foreach (self::phpFilesUnder('src') as $file) {
            $code = self::withoutComments((string) file_get_contents($file));

            if (preg_match('/\\b'.$catcherShort.'::(?:dispatchSync|dispatchNow)\\(|dispatch_sync\\(\\s*new\\s+\\\\?(?:[A-Za-z\\\\]+\\\\)?'.$catcherShort.'\\b/', $code) === 1) {
                return "{$file} runs {$catcherShort} synchronously";
            }

            if (preg_match('/\\(\\s*new\\s+\\\\?(?:[A-Za-z\\\\]+\\\\)?'.$catcherShort.'\\b[^;]*\\)\\s*->handle\\(/', $code) === 1) {
                return "{$file} calls {$catcherShort}'s handle() directly";
            }
        }

        if (array_key_exists($class, self::reachedFromCustomerRoutes($catcher))) {
            return 'the walk reaches it without passing through '.$catcherShort.': '
                .implode(' <- ', self::pathTo($class, self::reachedFromCustomerRoutes($catcher)));
        }

        return null;
    }

    /**
     * @return array<string, list<string>> code => files it was read in
     */
    private static function codes(): array
    {
        $codes = [];

        foreach (self::phpFilesUnder('src') as $file) {
            $source = self::withoutComments((string) file_get_contents($file));

            $found = [];

            foreach (self::CODE_SPELLINGS as $spelling) {
                preg_match_all($spelling, $source, $matches);
                array_push($found, ...$matches[1]);
            }

            foreach ($found as $code) {
                $codes[$code][] = $file;
            }
        }

        return $codes;
    }

    /**
     * @return array<string, list<string>> class => its uncatalogued codes
     */
    private static function uncataloguedCodesByClass(): array
    {
        $catalogued = self::flatten((array) require self::ROOT.'/lang/en/errors.php');
        $fileToClass = array_flip(self::classes());
        $byClass = [];

        foreach (self::codes() as $code => $files) {
            if (array_key_exists($code, $catalogued)) {
                continue;
            }

            foreach (array_unique($files) as $file) {
                $class = $fileToClass[$file] ?? $file;
                $byClass[$class][] = $code;
            }
        }

        return $byClass;
    }

    /**
     * Breadth-first from the customer route files; each reached class maps to
     * the class it was reached from (null for a start).
     *
     * @return array<string, string|null>
     */
    private static function reachedFromCustomerRoutes(?string $without = null): array
    {
        $classes = self::classes();
        $edges = self::edges();

        $reached = [];
        $queue = [];

        foreach ([self::ROOT.'/routes/api_v1.php', ...(glob(self::ROOT.'/routes/v1/*.php') ?: [])] as $routeFile) {
            preg_match_all('/Lynomia(?:\\\\[A-Za-z0-9_]+)+/', (string) file_get_contents($routeFile), $matches);

            foreach ($matches[0] as $name) {
                if (isset($classes[$name]) && $name !== $without && ! array_key_exists($name, $reached)) {
                    $reached[$name] = null;
                    $queue[] = $name;
                }
            }
        }

        while ($queue !== []) {
            $class = array_shift($queue);

            foreach ($edges[$class] ?? [] as $next) {
                if ($next !== $without && ! array_key_exists($next, $reached)) {
                    $reached[$next] = $class;
                    $queue[] = $next;
                }
            }
        }

        return $reached;
    }

    /**
     * @param  array<string, string|null>  $reached
     * @return list<string>
     */
    private static function pathTo(string $class, array $reached): array
    {
        $path = [];

        for ($at = $class; $at !== null; $at = $reached[$at]) {
            $path[] = substr($at, (int) strrpos($at, '\\') + 1);
        }

        return $path;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function edges(): array
    {
        if (self::$edges !== null) {
            return self::$edges;
        }

        $classes = self::classes();
        $edges = [];
        $implementedBy = [];

        foreach ($classes as $class => $file) {
            $source = self::withoutComments((string) file_get_contents($file));
            $namespace = substr($class, 0, (int) strrpos($class, '\\'));
            $body = (string) preg_replace('/^use\s+[^;]+;/m', '', $source);

            preg_match_all('/^use\s+(Lynomia\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $imports, PREG_SET_ORDER);
            $imported = [];

            foreach ($imports as $import) {
                $short = ($import[2] ?? '') !== '' ? $import[2] : substr($import[1], (int) strrpos($import[1], '\\') + 1);
                $imported[$short] = $import[1];

                if (isset($classes[$import[1]]) && preg_match('/\b'.preg_quote($short, '/').'\b/', $body) === 1) {
                    $edges[$class][] = $import[1];
                }
            }

            preg_match_all('/Lynomia(?:\\\\[A-Za-z0-9_]+)+/', $body, $written);
            foreach ($written[0] as $name) {
                if (isset($classes[$name])) {
                    $edges[$class][] = $name;
                }
            }

            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\b/', $body, $words);
            foreach (array_unique($words[1]) as $word) {
                $sibling = $namespace.'\\'.$word;

                if ($sibling !== $class && isset($classes[$sibling])) {
                    $edges[$class][] = $sibling;
                }
            }

            if (preg_match('/\bimplements\s+([^{]+)\{/', $body, $implements) === 1) {
                foreach (array_map('trim', explode(',', $implements[1])) as $interface) {
                    $interface = ltrim($interface, '\\');
                    $resolved = $imported[$interface] ?? (isset($classes[$interface]) ? $interface : $namespace.'\\'.$interface);

                    if (isset($classes[$resolved])) {
                        $implementedBy[$resolved][] = $class;
                    }
                }
            }
        }

        foreach ($implementedBy as $interface => $implementations) {
            $edges[$interface] ??= [];
            array_push($edges[$interface], ...$implementations);
        }

        foreach (self::listenersByEvent() as $event => $listeners) {
            $edges[$event] ??= [];
            array_push($edges[$event], ...$listeners);
        }

        return self::$edges = array_map(static fn (array $to): array => array_values(array_unique($to)), $edges);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function listenersByEvent(): array
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Providers/EventServiceProvider.php');

        preg_match_all('/^use\s+(Lynomia\\\\[A-Za-z0-9_\\\\]+);/m', $source, $imports);
        $alias = [];
        foreach ($imports[1] as $import) {
            $alias[substr($import, (int) strrpos($import, '\\') + 1)] = $import;
        }

        $map = [];

        if (preg_match('/protected \$listen = \[(.*?)\n    \];/s', $source, $listen) === 1) {
            preg_match_all('/(\w+)::class\s*=>\s*\[(.*?)\]/s', self::withoutComments('<?php '.$listen[1]), $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                if (! isset($alias[$pair[1]])) {
                    continue;
                }

                preg_match_all('/(\w+)::class/', $pair[2], $listeners);

                foreach ($listeners[1] as $listener) {
                    if (isset($alias[$listener])) {
                        $map[$alias[$pair[1]]][] = $alias[$listener];
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        $classes = [];

        foreach (self::phpFilesUnder('src') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) === 1
                && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $source, $declared) === 1) {
                $classes[$namespace[1].'\\'.$declared[1]] = $file;
            }
        }

        return self::$classes = $classes;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            self::ROOT.'/'.$directory,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @return array<string, true>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = true;
            }
        }

        return $flat;
    }
}
