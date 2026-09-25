<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
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
     * What importsIn() reads: the first name after a `use` that begins a line.
     */
    private const string IMPORT = '/^use\s+([^\s;]+)/m';

    /**
     * The names a file imports, as every import rule here sees them.
     *
     * That is top-level `use` statements and nothing else. A class named in a
     * docblock, written inline by its fully-qualified name, or held in a string
     * never reaches this list, so no rule built on it can see such a reference.
     * The agent instructions say so, and
     * the_agent_instructions_say_only_what_the_import_rules_can_see() probes
     * this method to keep them saying so.
     *
     * @return list<string>
     */
    private function importsIn(string $source): array
    {
        preg_match_all(self::IMPORT, $source, $matches);

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
         *
         * This reads `use` statements through importsIn(). A controller named
         * any other way - in a docblock, inline, or in a string - is invisible
         * here, and is held at zero by
         * no_module_names_another_modules_infrastructure_or_http_out_of_sight().
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

    /**
     * The file an agent reads before it touches anything here. `CLAUDE.md` is
     * the same text, and TheAgentInstructionsDescribeThisRepositoryTest keeps
     * it so, which is why only one of the two is read below.
     */
    private const string AGENT_INSTRUCTIONS = __DIR__.'/../../AGENTS.md';

    /**
     * The two layers the instructions' module-boundary paragraph is about.
     * `Domain` and `Application` are where cross-module work is meant to go,
     * so they are not boundaries the paragraph can claim or disclaim.
     */
    private const array THE_LAYERS_THE_BOUNDARY_PARAGRAPH_COVERS = ['Http', 'Infrastructure'];

    /**
     * Every sentence the instructions may use about what the import rules
     * see, mapped to the answers from probing importsIn() that make it true.
     *
     * The second is the sentence the paragraph used to carry. It is here so
     * that restoring it is red for the reason it was false, rather than
     * merely unrecognised.
     *
     * @var array<string, array<string, bool>>
     */
    private const array WHAT_THE_INSTRUCTIONS_MAY_SAY_THE_IMPORT_RULES_SEE = [
        'read `use` statements and nothing else' => [
            'a use statement' => true,
            'a docblock' => false,
            'an inline fully-qualified name' => false,
            'a string' => false,
        ],
        'including references in docblocks' => [
            'a docblock' => true,
        ],
    ];

    #[Test]
    public function a_layer_the_agent_instructions_call_a_boundary_is_one_no_module_crosses(): void
    {
        /*
         * AGENTS.md said a module never reaches into another module's
         * Infrastructure or Http, and that this test enforced it. Only the Http
         * half was ever asserted - deliberately, for the reason
         * no_module_calls_another_modules_http_layer() gives - while hundreds
         * of `use` statements crossed the other half. An agent reads that file
         * first and has no reason to doubt it.
         *
         * So the document's claims are measured rather than trusted. For each
         * layer the paragraph covers it must say exactly one of two things the
         * gate recognises: that no module reaches into it, or that reaching
         * into it is not asserted. Silence, both, or a rewording nobody taught
         * this test is red for that layer on its own - an unrecognised
         * sentence is no claim, and a gate that finds no claim passes, so a
         * check that only asked for "some claim somewhere" would let the
         * Infrastructure sentence be reworded into a lie while the Http one
         * kept it green.
         *
         * Then every layer it calls a boundary must measure zero crossings,
         * seen or unseen. No count is pinned: a layer is either a boundary,
         * and then nothing crosses it, or it is disclosed as not asserted.
         */
        $said = self::whatTheInstructionsSayAboutEachLayer(self::agentInstructions());

        $unclear = [];

        foreach (self::THE_LAYERS_THE_BOUNDARY_PARAGRAPH_COVERS as $layer) {
            $statements = (int) in_array($layer, $said['boundary'], true) + (int) in_array($layer, $said['not asserted'], true);

            if ($statements !== 1) {
                $unclear[] = sprintf('`%s`: %d recognised statements', $layer, $statements);
            }
        }

        $this->assertSame([], $unclear, "AGENTS.md must say, for each layer, either \"A module never reaches into another module's `<Layer>`\" or \"Reaching into another module's `<Layer>` is not asserted\" - exactly one:\n  ".implode("\n  ", $unclear));

        $crossed = [];

        foreach ($said['boundary'] as $layer) {
            $crossings = $this->crossingsInto($layer);

            if ($crossings !== []) {
                $crossed[] = sprintf('`%s` is crossed %d times, first %s', $layer, count($crossings), $crossings[0]);
            }
        }

        $this->assertSame([], $crossed, "AGENTS.md calls these layers a boundary between modules, and modules cross them. Either the code or the sentence is wrong; LayeringTest's Http rule explains why the Infrastructure one is not asserted:\n  ".implode("\n  ", $crossed));
    }

    #[Test]
    public function every_rule_the_agent_instructions_name_is_a_test_that_runs(): void
    {
        /*
         * "LayeringTest enforces this" named a file, not a rule, and half of
         * the rule it implied did not exist. A document that names its
         * enforcement by method can be checked, so it must, and each method it
         * names must be one PHPUnit runs. method_exists() alone would accept a
         * rule demoted to a private helper: the suite shrinks by one, every
         * remaining test stays green, and the document still points at it.
         */
        preg_match_all('/`(\w+Test)::(\w+)`/', self::agentInstructions(), $named, PREG_SET_ORDER);

        $this->assertNotSame([], $named, 'AGENTS.md names no rule as `Class::method`, so nothing it says is enforced can be checked.');

        $notRules = [];

        foreach ($named as [, $class, $method]) {
            $fqcn = __NAMESPACE__.'\\'.$class;

            if (! class_exists($fqcn) || ! method_exists($fqcn, $method)) {
                $notRules[] = $class.'::'.$method.' does not exist in '.__NAMESPACE__;

                continue;
            }

            $rule = new ReflectionMethod($fqcn, $method);
            $runs = $rule->isPublic() && ($rule->getAttributes(Test::class) !== [] || str_starts_with($method, 'test'));

            if (! $runs) {
                $notRules[] = $class.'::'.$method.' is not a test PHPUnit runs';
            }
        }

        $this->assertSame([], $notRules, "AGENTS.md names enforcement that does not run:\n  ".implode("\n  ", $notRules));
    }

    #[Test]
    public function the_agent_instructions_say_only_what_the_import_rules_can_see(): void
    {
        /*
         * The same paragraph said the rule held "including references in
         * docblocks". importsIn() reads `use` statements and nothing else, so a
         * docblock naming another module's controller left the Http rule
         * green. That was the clause that mattered most: a reference no rule
         * can see is exactly how a boundary gets crossed without anybody
         * noticing.
         *
         * So importsIn() is probed live, once per way of naming a class, and
         * each sentence the document may use about what the rules see must
         * agree with the probe. Teach importsIn() to read docblocks and the
         * current sentence goes red until it is corrected; restore the old
         * sentence without doing so and it goes red for the reason it was
         * false.
         */
        $controller = 'Lynomia\\Modules\\Billing\\Http\\Controllers\\InvoiceController';

        $probes = [
            'a use statement' => "<?php\n\nuse {$controller};\n",
            'a docblock' => "<?php\n\n/**\n * {@see \\{$controller}}\n */\nfinal class Probe {}\n",
            'an inline fully-qualified name' => "<?php\n\nfinal class Probe\n{\n    public const string C = \\{$controller}::class;\n}\n",
            'a string' => "<?php\n\nfinal class Probe\n{\n    public const string C = '{$controller}';\n}\n",
        ];

        $sees = [];

        foreach ($probes as $way => $source) {
            $sees[$way] = in_array($controller, $this->importsIn($source), true);
        }

        $text = self::agentInstructions();
        $recognised = 0;
        $false = [];

        foreach (self::WHAT_THE_INSTRUCTIONS_MAY_SAY_THE_IMPORT_RULES_SEE as $sentence => $requires) {
            if (! str_contains($text, $sentence)) {
                continue;
            }

            $recognised++;

            foreach ($requires as $way => $seen) {
                if ($sees[$way] !== $seen) {
                    $false[] = sprintf('"%s" needs importsIn() %s %s, and it does%s', $sentence, $seen ? 'to see' : 'not to see', $way, $seen ? ' not' : '');
                }
            }
        }

        $this->assertNotSame(0, $recognised, 'AGENTS.md no longer says what the import rules can see in any sentence this gate recognises.');
        $this->assertSame([], $false, "AGENTS.md says something about the import rules that importsIn() does not do:\n  ".implode("\n  ", $false));
    }

    #[Test]
    public function no_module_names_another_modules_infrastructure_or_http_out_of_sight(): void
    {
        /*
         * The import rules read `use` statements, so the one crossing they are
         * blind to is the one nobody can see: another module's class named in
         * a docblock, written inline by its full name, held in a string, or
         * assembled at runtime from a namespace prefix - the shape
         * ReferenceTopologyValidator uses to reach Monitoring's collectors. That
         * one is in Application, which this rule does not cover; it is named in
         * the agent instructions instead.
         *
         * Reaching another module's Infrastructure is allowed and reaching its
         * Http is not, but either is done in a `use` line, where it is counted,
         * or not at all. This holds everything else at zero for both layers. It
         * is a property rather than a list: nothing is exempt, and there is no
         * count to keep up to date.
         *
         * The scanner is shown every shape first, and a few it must not
         * report. A scan that fails to parse a file does not go red; it reports
         * the clean tree one was hoping for.
         */
        foreach (self::outOfSightCrossingsTheScanMustFind() as $shape => $source) {
            $this->assertNotSame([], self::crossingsOutOfSight($source, 'Orders', self::THE_LAYERS_THE_BOUNDARY_PARAGRAPH_COVERS), 'The scan cannot see '.$shape.', so a clean result from it would mean nothing.');
        }

        foreach (self::referencesTheScanMustNotReport() as $shape => $source) {
            $this->assertSame([], self::crossingsOutOfSight($source, 'Orders', self::THE_LAYERS_THE_BOUNDARY_PARAGRAPH_COVERS), 'The scan reports '.$shape.', which is not a crossing it exists to find.');
        }

        $violations = [];

        foreach ($this->phpFiles(self::SRC.'/Modules') as $file) {
            $own = explode('/', $file['relative'])[1] ?? '';

            foreach (self::crossingsOutOfSight($file['source'], $own, self::THE_LAYERS_THE_BOUNDARY_PARAGRAPH_COVERS) as $reference) {
                $violations[] = $file['relative'].':'.$reference;
            }
        }

        $this->assertSame([], $violations, "Another module's Infrastructure or Http named where no import rule can see it. Import it with `use` (Infrastructure only) or do not name it:\n  ".implode("\n  ", $violations));
    }

    /**
     * The agent instructions with every run of whitespace collapsed to one
     * space. The file is hard-wrapped at 80 columns and a sentence straddles a
     * line break more often than not; matched against the raw file, such a
     * claim is not recognised at all, and an unrecognised claim is a pass.
     */
    private static function agentInstructions(): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(self::AGENT_INSTRUCTIONS));
    }

    /**
     * The layers the instructions call a boundary between modules, and the
     * layers they say crossing into is not asserted.
     *
     * @return array{boundary: list<string>, 'not asserted': list<string>}
     */
    private static function whatTheInstructionsSayAboutEachLayer(string $text): array
    {
        $boundary = [];

        preg_match_all("/never reach(?:es)? into another module's ((?:`\\w+`(?:,? (?:or|and|nor) |, )?)+)/", $text, $claims);

        foreach ($claims[1] as $list) {
            preg_match_all('/`(\w+)`/', $list, $layers);
            array_push($boundary, ...$layers[1]);
        }

        preg_match_all("/Reaching into another module's `(\\w+)` is not asserted/", $text, $disclosed);

        return [
            'boundary' => array_values(array_unique($boundary)),
            'not asserted' => array_values(array_unique($disclosed[1])),
        ];
    }

    /**
     * Every place a module names another module's class in $layer: the `use`
     * statements importsIn() reads, and everything it cannot.
     *
     * @return list<string>
     */
    private function crossingsInto(string $layer): array
    {
        $found = [];

        foreach ($this->phpFiles(self::SRC.'/Modules') as $file) {
            $own = explode('/', $file['relative'])[1] ?? '';

            foreach ($this->importsIn($file['source']) as $import) {
                if (preg_match('/^Lynomia\\\\Modules\\\\(\w+)\\\\'.preg_quote($layer, '/').'\\\\/', $import, $m) === 1 && $m[1] !== $own) {
                    $found[] = $file['relative'].' -> '.$import;
                }
            }

            foreach (self::crossingsOutOfSight($file['source'], $own, [$layer]) as $reference) {
                $found[] = $file['relative'].':'.$reference;
            }
        }

        return $found;
    }

    /**
     * Every name of another module's class in one of $layers that importsIn()
     * cannot see, as "line: name".
     *
     * What importsIn() reads is blanked out first, by the same pattern and
     * with the same length, so a `use` line is not reported and line numbers
     * still point at the file. What is left is read through PHP's own
     * tokenizer, because a hand-rolled one gets strings wrong - apostrophes in
     * prose are enough - and reports a clean tree. Comments, docblocks, inline
     * names and string literals (single or double backslashes) are read.
     *
     * The module named `Infrastructure` is a module, not a layer:
     * `Lynomia\Modules\Infrastructure\Domain\...` crosses into nobody's
     * Infrastructure layer, and is not reported.
     *
     * A string that stops at `Lynomia\Modules\` and leaves the module name to
     * runtime is reported too, whatever the layer, because no reading of the
     * source can tell whose module it reaches.
     *
     * @param  list<string>  $layers
     * @return list<string>
     */
    private static function crossingsOutOfSight(string $source, string $own, array $layers): array
    {
        $named = '/Lynomia\\\\{1,2}Modules\\\\{1,2}(\w+)\\\\{1,2}(?:'.implode('|', $layers).')(?!\w)/';
        $spliced = '/Lynomia\\\\{1,2}Modules\\\\{1,2}(?![\w\\\\])/';

        $unseen = (string) preg_replace_callback(
            self::IMPORT,
            static fn (array $m): string => substr($m[0], 0, -strlen($m[1])).str_repeat(' ', strlen($m[1])),
            $source,
        );

        $found = [];

        foreach (token_get_all($unseen) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            $isString = in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);

            if (! $isString && ! in_array($id, [T_COMMENT, T_DOC_COMMENT, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            if (preg_match_all($named, $text, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($hits as $hit) {
                    if ($hit[1][0] !== $own) {
                        $found[] = ($line + substr_count($text, "\n", 0, $hit[0][1])).': '.$hit[0][0];
                    }
                }
            }

            if ($isString && preg_match($spliced, $text, $hit) === 1) {
                $found[] = $line.': '.$hit[0].' followed by a module name chosen at runtime';
            }
        }

        return $found;
    }

    /**
     * One source per way of naming another module's class out of sight, each
     * as it would appear in a file of the Orders module.
     *
     * @return array<string, string>
     */
    private static function outOfSightCrossingsTheScanMustFind(): array
    {
        $file = static fn (string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Lynomia\\Modules\\Orders\\Application\\Actions;\n\n".$body;

        return [
            'a docblock' => $file(<<<'PHP'
                /**
                 * Hands the result to {@see \Lynomia\Modules\Billing\Http\Controllers\InvoiceController}.
                 */
                final class Planted {}
                PHP),
            'a comment in prose, among apostrophes' => $file(<<<'PHP'
                final class Planted
                {
                    // It's Billing's row and it isn't ours: 'Lynomia\Modules\Billing\Infrastructure\Models\Invoice'.
                    public function run(): void {}
                }
                PHP),
            'an inline fully-qualified name' => $file(<<<'PHP'
                final class Planted
                {
                    public function run(): int
                    {
                        return \Lynomia\Modules\Catalog\Infrastructure\Models\Plan::query()->count();
                    }
                }
                PHP),
            'a class name in a string' => $file(<<<'PHP'
                final class Planted
                {
                    private const string MODEL = 'Lynomia\\Modules\\Catalog\\Infrastructure\\Models\\Plan';
                }
                PHP),
            'a namespace prefix finished at runtime' => $file(<<<'PHP'
                final class Planted
                {
                    private const string ADAPTERS = 'Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\';

                    public function run(string $driver): bool
                    {
                        return class_exists(self::ADAPTERS.$driver);
                    }
                }
                PHP),
            'an interpolated string' => $file(<<<'PHP'
                final class Planted
                {
                    public function run(string $name): string
                    {
                        return "Lynomia\\Modules\\Billing\\Http\\Controllers\\{$name}";
                    }
                }
                PHP),
            'a module name chosen at runtime' => $file(<<<'PHP'
                final class Planted
                {
                    public function run(string $module): string
                    {
                        return 'Lynomia\\Modules\\'.$module.'\\Infrastructure\\Models\\Plan';
                    }
                }
                PHP),
        ];
    }

    /**
     * References the scan must leave alone, in a file of the Orders module.
     *
     * @return array<string, string>
     */
    private static function referencesTheScanMustNotReport(): array
    {
        $file = static fn (string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Lynomia\\Modules\\Orders\\Application\\Actions;\n\n".$body;

        return [
            'a use statement, which the import rules do see' => $file(<<<'PHP'
                use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;

                final class Planted {}
                PHP),
            "the file's own module" => $file(<<<'PHP'
                /**
                 * Answers {@see \Lynomia\Modules\Orders\Http\Controllers\OrderController}.
                 */
                final class Planted {}
                PHP),
            "another module's Domain" => $file(<<<'PHP'
                final class Planted
                {
                    private const string KIND = \Lynomia\Modules\Catalog\Domain\Enums\ProductKind::class;
                }
                PHP),
            'the Domain layer of the module named Infrastructure' => $file(<<<'PHP'
                final class Planted
                {
                    private const string VALUES = 'Lynomia\\Modules\\Infrastructure\\Domain\\Reference\\ReferenceValues';
                }
                PHP),
        ];
    }
}
