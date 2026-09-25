<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The rules that keep the modular monolith modular.
 *
 * None of these is enforceable by a type system, and every one of them is
 * violated the same way: not by a decision, but by an afternoon where the
 * import that made the deadline was the one that crossed a boundary. Six months
 * of those and the modules are a directory naming convention.
 *
 * They run against the source text rather than through reflection, so a rule
 * cannot be satisfied by a class that happens not to be loaded.
 */
final class LayeringTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    /**
     * @return list<array{path: string, relative: string, source: string}>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            $files[] = [
                'path' => $path,
                'relative' => str_replace(self::SRC.'/', '', $path),
                'source' => (string) file_get_contents($path),
            ];
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function importsIn(string $source): array
    {
        preg_match_all('/^use\s+([^\s;]+)/m', $source, $matches);

        return $matches[1];
    }

    #[Test]
    public function the_domain_layer_never_reaches_the_http_layer(): void
    {
        /*
         * Note what this rule does NOT say.
         *
         * The obvious version - "domain must not touch persistence" - is not
         * the architecture this codebase has, and asserting it would be a test
         * describing somebody's preference rather than the system. The domain
         * services here deliberately operate on Eloquent models: IpAllocator
         * issues `SELECT ... FOR UPDATE SKIP LOCKED` because correct address
         * allocation IS a database-level concern, and pushing it behind a
         * repository interface would hide the one line that makes it correct.
         *
         * What must hold is the direction of the dependency. The domain is
         * reachable from an HTTP request, a queue worker, a console command and
         * a reconciliation job; the moment it imports a controller, a form
         * request or a resource, it is reachable from exactly one of those, and
         * the others quietly stop being able to do the same work.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC.'/Modules') as $file) {
            if (! str_contains($file['relative'], '/Domain/')) {
                continue;
            }

            foreach ($this->importsIn($file['source']) as $import) {
                $forbidden = str_contains($import, '\\Http\\')
                    || str_starts_with($import, 'Illuminate\\Http\\')
                    // A provider adapter is one specific way of talking to one
                    // specific vendor. The domain talks to the contract.
                    || str_contains($import, '\\Infrastructure\\Providers\\');

                if ($forbidden) {
                    $violations[] = $file['relative'].' -> '.$import;
                }
            }
        }

        $this->assertSame([], $violations, "Domain code reaching the HTTP layer or a vendor adapter:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function value_objects_and_enums_are_constructible_without_a_database(): void
    {
        /*
         * The narrow version of the rule above, and the one that carries its
         * weight. Money, VmResources, Cidr and the enums are the pieces every
         * other test builds its fixtures from; if constructing one requires a
         * connection, the fast unit tests become slow integration tests and
         * people stop writing them.
         */
        $violations = [];

        foreach (['ValueObjects', 'Enums', 'DTOs'] as $kind) {
            foreach ($this->phpFiles(self::SRC.'/Modules') as $file) {
                if (! str_contains($file['relative'], '/Domain/'.$kind.'/')) {
                    continue;
                }

                foreach ($this->importsIn($file['source']) as $import) {
                    if (str_starts_with($import, 'Illuminate\\Database') || $import === 'Illuminate\\Support\\Facades\\DB') {
                        $violations[] = $file['relative'].' -> '.$import;
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Value objects needing a database:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function no_controller_opens_a_database_transaction(): void
    {
        /*
         * A controller that owns a transaction is business logic that only an
         * HTTP request can execute. The same operation then cannot be retried
         * by a worker, replayed by a reconciliation job, or called by an
         * administrator without going through the customer's own endpoint —
         * and on a billing system, "we can only do this over HTTP" is how a
         * failed payment becomes a manual database edit.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            if (! str_contains($file['relative'], '/Controllers/')) {
                continue;
            }

            if (preg_match('/DB::(transaction|beginTransaction)\b/', $file['source']) === 1) {
                $violations[] = $file['relative'];
            }
        }

        $this->assertSame([], $violations, 'Controllers owning a transaction: '.implode(', ', $violations));
    }

    #[Test]
    public function every_eloquent_model_is_guarded_rather_than_fillable(): void
    {
        /*
         * $fillable is an allow-list somebody has to remember to extend;
         * $guarded is a deny-list that fails closed. On a model with a
         * customer_id or an amount column, forgetting to extend an allow-list
         * is a lost feature, but forgetting to extend a deny-list is mass
         * assignment of the field that decides who owns the row.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            if (! str_contains($file['source'], 'extends Model')) {
                continue;
            }

            if (str_contains($file['source'], '$fillable')) {
                $violations[] = $file['relative'].' declares $fillable';

                continue;
            }

            if (! str_contains($file['source'], '$guarded')) {
                $violations[] = $file['relative'].' declares neither';
            }
        }

        $this->assertSame([], $violations, "Mass-assignment exposure:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function configuration_is_read_through_config_and_never_through_env(): void
    {
        /*
         * `php artisan config:cache` is standard in production, and it makes
         * every env() call outside a config file return null. The failure is
         * silent and environment-specific: it works everywhere except the place
         * that matters.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            // Comments are stripped first. A rule that reads prose punishes the
            // one thing this codebase wants most — a class explaining why it
            // does what it does — and it did: a docblock stating why a guard
            // must not use the helper was itself reported as using it.
            $code = self::withoutComments($file['source']);

            if (preg_match_all('/\benv\s*\(/', $code, $matches) > 0) {
                $violations[] = $file['relative'].' ('.count($matches[0]).')';
            }
        }

        $this->assertSame([], $violations, "env() outside config/:\n  ".implode("\n  ", $violations));
    }

    /**
     * The file's source with every comment removed, so a rule matching on code
     * cannot be tripped by a sentence about that code.
     */
    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    #[Test]
    public function no_debugging_statement_survives_in_the_source(): void
    {
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            if (preg_match('/(?<![\w:>$])(dd|dump|var_dump|print_r|ray|die)\s*\(/', $file['source'], $m) === 1) {
                $violations[] = $file['relative'].' → '.$m[1].'()';
            }
        }

        $this->assertSame([], $violations, "Debugging left in source:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function money_is_never_cast_to_a_float(): void
    {
        /*
         * The whole point of integer minor units is that no step of an amount's
         * life is a binary float. One (float) cast anywhere in the chain undoes
         * it, and the resulting error is a fraction of a fils per transaction:
         * far too small to notice in a test and exactly large enough to make a
         * month's ledger not reconcile.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            if (preg_match_all('/\(float\)\s*\$\w*(?:[Aa]mount|[Mm]inor|[Pp]rice|[Tt]otal|[Bb]alance)\w*/', $file['source'], $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $violations[] = $file['relative'].' → '.$match;
                }
            }
        }

        $this->assertSame([], $violations, "Money cast to float:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function every_domain_exception_carries_a_stable_error_code(): void
    {
        // The HTTP layer translates exceptions into a machine-readable code
        // without matching on prose. An exception that does not supply one
        // would have to be identified by its message, which is the thing most
        // likely to be reworded.
        $violations = [];

        foreach ($this->phpFiles(self::SRC) as $file) {
            if (! str_contains($file['relative'], '/Exceptions/')) {
                continue;
            }

            if (preg_match('/\babstract\s+class\b/', $file['source']) === 1) {
                continue;
            }

            if (! str_contains($file['source'], 'extends DomainException')) {
                continue;
            }

            if (! str_contains($file['source'], 'function errorCode')) {
                $violations[] = $file['relative'];
            }
        }

        $this->assertSame([], $violations, "Domain exceptions without an error code:\n  ".implode("\n  ", $violations));
    }

    /**
     * Who may reach each action that ends a service, in production.
     *
     * F-18 closed the hosting-account route with two layers — a controller
     * gate and a refusal inside TerminateHostingAccount — and F-19 then opened
     * a second route to the same action that inherits only the second layer.
     * That is safe because the new route asks EndOfService::authorityOver()
     * for both permissions itself, and it stays safe only while nothing else
     * reaches these actions around it: a third caller would be a door with
     * whatever gate its author remembered. So the door set is measured here,
     * not described.
     *
     * Production only. Tests call these actions directly, which is the point of
     * having them; the claim in EndOfService's docblock is scoped to production
     * for the same reason.
     *
     * @var array<string, list<string>> class => every file under src/ and app/ that names it
     */
    private const array THE_DOORS_TO_ENDING_A_SERVICE = [
        'Lynomia\\Support\\Lifecycle\\EndOfService' => [
            'src/Modules/Admin/Http/Controllers/ServiceController.php',
            'src/Modules/Provisioning/Application/Actions/EndExpiredServices.php',
        ],
        'Lynomia\\Modules\\Vps\\Application\\Actions\\TerminateVpsService' => [
            'src/Support/Lifecycle/EndOfService.php',
        ],
        'Lynomia\\Modules\\Dedicated\\Application\\Actions\\DecommissionDedicatedServer' => [
            'src/Support/Lifecycle/EndOfService.php',
        ],
        'Lynomia\\Modules\\SharedHosting\\Application\\Actions\\EndHostingService' => [
            'src/Support/Lifecycle/EndOfService.php',
        ],
        'Lynomia\\Modules\\SharedHosting\\Application\\Actions\\TerminateHostingAccount' => [
            'src/Modules/Admin/Http/Controllers/HostingController.php',
            'src/Modules/SharedHosting/Application/Actions/EndHostingService.php',
        ],
        'Lynomia\\Modules\\Provisioning\\Application\\Actions\\EndAnUnbuiltService' => [
            'src/Modules/Dedicated/Application/Actions/DecommissionDedicatedServer.php',
            'src/Modules/SharedHosting/Application/Actions/EndHostingService.php',
            'src/Modules/Vps/Application/Actions/TerminateVpsService.php',
        ],
    ];

    #[Test]
    public function every_way_to_end_a_service_is_a_door_somebody_chose(): void
    {
        $root = (string) realpath(self::SRC.'/..');
        $found = array_fill_keys(array_keys(self::THE_DOORS_TO_ENDING_A_SERVICE), []);

        foreach ([...$this->phpFiles($root.'/src'), ...$this->phpFiles($root.'/app')] as $file) {
            $code = self::withoutComments($file['source']);
            $namespace = preg_match('/^namespace\s+([^;]+);/m', $code, $m) === 1 ? $m[1] : '';
            $relative = substr((string) realpath($file['path']), strlen($root) + 1);

            foreach (array_keys(self::THE_DOORS_TO_ENDING_A_SERVICE) as $class) {
                $cut = (int) strrpos($class, '\\');
                $short = substr($class, $cut + 1);
                $home = substr($class, 0, $cut);

                if ($namespace === $home && preg_match('/\b(class|interface|trait)\s+'.$short.'\b/', $code) === 1) {
                    // The class's own file.
                    continue;
                }

                /*
                 * Named in full — an import or an inline reference — or by its
                 * short name from a file in its own namespace, which needs no
                 * import at all.
                 */
                $named = str_contains($code, $class)
                    || ($namespace === $home && preg_match('/\b'.$short.'\b/', $code) === 1);

                if ($named) {
                    $found[$class][] = $relative;
                }
            }
        }

        $expected = self::THE_DOORS_TO_ENDING_A_SERVICE;

        foreach (array_keys($expected) as $class) {
            sort($expected[$class]);
            sort($found[$class]);
        }

        $this->assertSame(
            $expected,
            $found,
            'A service can be ended through a door nobody chose. Every caller of these actions must ask EndOfService::authorityOver() or be one of the routes that already do (F-19 × F-18).',
        );
    }

    #[Test]
    public function no_module_calls_another_modules_http_layer(): void
    {
        /*
         * This is the module boundary that is actually real here.
         *
         * The stricter rule - a module may only use another module's Domain -
         * is not the architecture this codebase has, and it is not obviously
         * the one it should have: Orders genuinely needs Catalog's Plan model
         * to price a line, and routing that through a DTO would add a layer
         * whose only job is to be a layer. Asserting it would leave a
         * permanently red test, which teaches people to ignore red tests.
         *
         * What must never happen is a module reaching another module's HTTP
         * surface. A controller is the answer to "what does a customer's
         * request look like", not "how do I get an invoice"; calling one from
         * another module couples two features to a URL shape and drags request
         * parsing into the middle of a domain operation.
         */
        $violations = [];

        foreach ($this->phpFiles(self::SRC.'/Modules') as $file) {
            $parts = explode('/', $file['relative']);
            $own = $parts[1] ?? '';

            if ($own === '') {
                continue;
            }

            foreach ($this->importsIn($file['source']) as $import) {
                if (preg_match('/^Lynomia\\\\Modules\\\\(\w+)\\\\Http\\\\/', $import, $m) !== 1) {
                    continue;
                }

                if ($m[1] !== $own) {
                    $violations[] = $own.' -> '.$import.' ('.$file['relative'].')';
                }
            }
        }

        $this->assertSame([], $violations, "Cross-module reach into an HTTP layer:\n  ".implode("\n  ", $violations));
    }
}
