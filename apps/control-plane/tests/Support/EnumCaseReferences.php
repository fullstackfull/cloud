<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use FilesystemIterator;
use Lynomia\Modules\Shared\Domain\Contracts\StateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;
use Tests\Architecture\EveryEnumCaseHasAProducerTest;
use Tests\Architecture\EveryStateAMachineCanEnterHasAProducerTest;
use Tests\Architecture\NoDeadMethodsTest;
use UnitEnum;

/**
 * Where production code names an enum case, and whether it names it to put
 * the case somewhere or only to ask about it.
 *
 * The classifier behind two gates:
 * {@see EveryStateAMachineCanEnterHasAProducerTest} (every state a machine can
 * enter) and {@see EveryEnumCaseHasAProducerTest} (every case of every enum
 * declared under `src`). What it reads and what it does not see is stated
 * here once, because both gates' verdicts are only as good as this list.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * **Subjects.** Every enum declared in a PHP file under `src`, found by
 * tokenising the declarations (`enum Name`), and every case of each.
 *
 * **Machines** are discovered, not listed: every concrete class in `src` that
 * implements {@see StateMachine}, found by closing the name `StateMachine`
 * transitively over every `extends`/`implements` clause in `src` to a
 * fixpoint. The closure matters: an interface `Lifecycle extends
 * StateMachine` with a machine naming only `Lifecycle` would otherwise go
 * undiscovered, **and** an undiscovered machine would be absent from the
 * producer scan's skip list, so its own transition table would count as
 * production code writing its states and a dead state would be concealed.
 *
 * **Producers** are found by tokenising (`PhpToken`, not a regex) every PHP
 * file under `src` and `app`, **except the machines' own files** — a table
 * says what is legal, which is precisely what is not being asked. Test code,
 * factories under `database/` and seeders are not production and are not
 * read. A reference to a case, `Enum::Case` (or `self::Case` / `static::Case`
 * inside the enum), counts as a producer unless it stands in one of these
 * reading positions:
 *
 *  - an operand of `===`, `!==`, `==` or `!=`;
 *  - a condition of a `match` arm (left of its `=>`), including an element
 *    of an array literal that is the condition — `match ([$a, $b]) {
 *    [Enum::X, Enum::Y] => ... }` — descended through nested array literals
 *    only (a call or an index inside is a boundary). `FactSource::Declared`
 *    stood as produced by `mayReplace()`'s condition lists before this;
 *  - anywhere inside an **enum's own transition table**, which is read the
 *    way a machine's file is skipped: in an enum declared in the file, the
 *    body of a method named `canBecome` whose declared return type is
 *    exactly `bool`, and the body of every `private` method of the same enum
 *    called at least once and only from inside such a body (as
 *    `$this->name(`, `self::name(` or `static::name(`; one level, not
 *    transitively). A `bool` answer cannot hand a case on, and a private
 *    helper only it calls cannot either. A public helper (`BackupState::
 *    allowedNext()`) can be called from anywhere and stays a producer.
 *    `DomainState::TransferredAway` stood as produced by its own `canBecome()`
 *    before this;
 *  - a `case` label of a `switch`;
 *  - a member of a set that is being DECLARED, TESTED or WALKED, at five named
 *    positions: an element of a list literal initialising a `const`; an
 *    element of a list literal initialising a property (`private array $x =
 *    [...]`); an element of the haystack literal of `in_array()` /
 *    `array_search()`, long form `array(...)` included and nested literals
 *    descended; an element of a list literal that is the subject of a
 *    `foreach`; and the `switch` label above;
 *  - an argument of a **transition guard**: a method call whose name is one of
 *    the methods {@see StateMachine} declares with a `bool` or `void` return
 *    type and at least one parameter (today `canTransition` and
 *    `assertCanTransition`, read from the interface by reflection, not
 *    listed). Such a call can neither write through its argument nor return
 *    it, and {@see guardNamesDeclaredOutsideTheMachines()} holds the condition that
 *    makes the name enough: no class outside the machine closure declares a
 *    method so named;
 *  - anywhere inside the array given to `->withContext(...)`, the method
 *    {@see DomainException} declares to attach a context to a refusal: it
 *    stores the array on the exception and returns the exception, so
 *    `'required_status' => Enum::X->value` there describes a refusal and
 *    writes nothing. {@see contextMethodDeclarations()} holds that no other
 *    class declares the name.
 *
 * The guard rule is why a `$states->assertCanTransition($from, Enum::X)`
 * beside the write is not itself counted as the write. Before it, the most
 * common shape in the tree — a guard call next to a `forceFill` — made the
 * guard a producer, and removing the write left the state "produced" by its
 * own guard.
 *
 * The `foreach` rule is why a precedence list —
 * `foreach ([Enum::A, Enum::B] as $first) { if (in_array($first, $live)) ... }`
 * — is not counted as producing its members. It reads the loop variable as a
 * walk over a declared set, which is the same claim the `const` rule makes.
 * It is not conservative: `foreach ([Enum::A] as $s) { $model->status = $s; }`
 * is a real write this reads as a read. That direction fails loudly — the
 * gate names a state as unwritten, and the answer is to name the case where it
 * is written — whereas the other direction hid `OrderStatus::ProvisioningFailed`
 * losing both of its real writers.
 *
 * At the two initialiser positions a **keyed** value is not a member: in
 * `protected $attributes = ['status' => Enum::X]` Eloquent writes the value
 * into every new row, and in `const D = ['status' => Enum::X]` the same value
 * is one `self::D` away from the same place. So list elements are a declared
 * set and keyed values are producers, at the initialiser and `foreach`
 * positions alike; a case standing alone as an initialiser (`const X =
 * Enum::Y`) is a named value, and a producer.
 *
 * `MEMBERSHIP_PREDICATES` is closed to `in_array` and `array_search` by a
 * stated safety condition: the callee must be unable to write through the
 * argument **and** unable to return an element of it. That is why
 * `array_merge` and `array_replace` are absent, and why a method call is not
 * recognised however it is spelled — the transition guards and `withContext`
 * above are the two exceptions, and each carries its own condition. **An
 * unrecognised callee is
 * treated as a producer.** Inside a haystack a call or an index is a
 * boundary: `[wrap(Enum::A)]` and `[$map[Enum::A], Enum::B]` each keep the
 * wrapped or indexed reference a producer.
 *
 * **The default flips for the scalar spelling.** A bare `Enum::X` defaults to
 * producer; `Enum::X->value` (any `->` projection) defaults to reader, because
 * the scalar form exists to cross into the query builder — `where('status',
 * '!=', Retired->value)`, `whereNotIn('status', [...])` — and this classifier
 * does not read query builders. The exception is assignment: after `=>`, `=`
 * or `??=` the scalar is being handed to something that stores it, so it is
 * classified as the bare case would be. A query-builder write is *required*
 * to use that spelling, because it bypasses the enum cast.
 *
 * **Construction from a value** — `Enum::from(...)` and `Enum::tryFrom(...)`
 * — is recorded separately, per enum, in {@see constructions()}, and is never
 * a producer of any case: it says only that some value crosses into that
 * enum somewhere. `Enum::cases()` is recorded nowhere; walking every case is
 * not producing any of them.
 *
 * ===========================================================================
 * WHAT IT DOES NOT SEE
 * ===========================================================================
 *
 *  - **Strings.** `'status' => 'cancelled'`, a column default in a migration
 *    and the value handed to `from()` are invisible in both directions. A
 *    write spelled as a string is not counted, and a reader spelled as a
 *    string — such as `ReapExpiredReservations::TERMINAL_FAILURE_STATUSES` —
 *    is not either.
 *  - **A `->value` write that is not assignment-shaped**, for instance a
 *    scalar passed positionally to a setter, reads as a read.
 *  - **A read that is assignment-shaped reads as a write.**
 *    `where('status', '!=', $excluded = Enum::X->value)` and Laravel's
 *    array-form `where(['status' => Enum::X->value])` are both reads, and
 *    both are counted as producers. The property cannot be closed inside a
 *    token classifier: the obvious fix would also stop counting
 *    `insertOrIgnore(['status' => Enum::X->value])`, which is a write whose
 *    array is equally an argument to a query-builder call. So when a gate
 *    goes red saying an excused case now has a writer, establish which kind
 *    of red it is first: **if the only change near the site is how the
 *    scalar is spelled at a query, revert the spelling alone; a real writer
 *    survives that.**
 *  - **A bare case given to a query builder** — `where('status', Enum::X)` —
 *    counts as a producer, though it is a read. That over-counts writers, and
 *    an over-counted writer can hide a real one's removal: the gate stays
 *    green on a state whose only remaining mention is such a read. It never
 *    calls a written state unwritten.
 *  - **A list returned or handed on as a set.** Every other array literal
 *    counts its elements as producers. `CustomerServiceState::
 *    underlyingStatuses()` returns lists of `ServiceStatus` cases that a
 *    filter reads, so the machine gate stays green when
 *    `ServiceStatus::Reactivating`'s one real write
 *    (`EnforceServiceStateForSubscription`) or `ServiceStatus::Failed`'s
 *    (`RunProvisioningJob`) is removed. No rule here can tell such a list
 *    from one that is written, so those two states are held by behavioural
 *    tests instead: `SubscriptionSuspensionLifecycleTest::
 *    payment_moves_the_service_through_reactivating_before_active` and
 *    `RunProvisioningJobTest::a_permanent_failure_is_never_retried`, each of
 *    which goes red when that write is removed.
 *  - **Nested literal shapes beyond arrays.** A haystack or a walked list is
 *    descended through array literals only; anything else inside it is a
 *    boundary.
 *  - **A write inside an enum's transition table.** Everything in a table
 *    body is read as a read, so a `canBecome()` that also stored a case
 *    somewhere would hide that store. No such body in the tree does.
 *  - **Reachability of the writer itself.** A producer is a site that names
 *    the case in a writing position. Whether that site's method is ever
 *    called is {@see NoDeadMethodsTest}'s question.
 */
final class EnumCaseReferences
{
    public const string ROOT = __DIR__.'/../..';

    /** @var list<string> */
    public const array PRODUCTION = ['src', 'app'];

    /**
     * Callees whose array argument is a haystack: they can neither write
     * through it nor return an element of it.
     *
     * @var list<string>
     */
    public const array MEMBERSHIP_PREDICATES = ['in_array', 'array_search'];

    /**
     * The one method whose array argument is an exception's context: declared
     * only by {@see DomainException}, it stores the array on the exception and
     * returns the exception, so a case named inside it describes a refusal and
     * puts nothing into a row. {@see contextMethodDeclarations()} holds that it
     * is declared nowhere else.
     */
    public const string CONTEXT_METHOD = 'withcontext';

    /** @var array<string, list<array{string, int, string}>>|null */
    private static ?array $sites = null;

    /** @var array<class-string, list<array{string, int}>>|null */
    private static ?array $constructions = null;

    /** @var array<class-string<UnitEnum>, list<string>>|null */
    private static ?array $enums = null;

    /** @var array<class-string, string>|null */
    private static ?array $machineFiles = null;

    /** @var list<string>|null */
    private static ?array $guardNames = null;

    /**
     * Every enum declared under `src`, with its case names.
     *
     * @return array<class-string<UnitEnum>, list<string>>
     */
    public static function enums(): array
    {
        if (self::$enums !== null) {
            return self::$enums;
        }

        $enums = [];

        foreach (self::files(['src']) as $file) {
            foreach (self::declarations($file) as [$class, , , $kind]) {
                if ($kind === 'enum' && enum_exists($class)) {
                    $enums[$class] = array_map(static fn (UnitEnum $case): string => $case->name, $class::cases());
                }
            }
        }

        ksort($enums);

        return self::$enums = $enums;
    }

    /**
     * @return list<StateMachine<BackedEnum>>
     */
    public static function machines(): array
    {
        $machines = [];

        foreach (self::machineFiles() as $class => $file) {
            $reflection = new ReflectionClass($class);

            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(StateMachine::class)) {
                continue;
            }

            /** @var StateMachine<BackedEnum> $machine */
            $machine = $reflection->newInstanceWithoutConstructor();
            $machines[] = $machine;
        }

        return $machines;
    }

    /**
     * The enums some machine's transition table names, as FQCN → true.
     *
     * @return array<class-string, true>
     */
    public static function governedEnums(): array
    {
        $governed = [];

        foreach (self::machines() as $machine) {
            foreach ($machine->transitions() as $targets) {
                foreach ($targets as $target) {
                    $governed[$target::class] = true;
                }
            }
        }

        return $governed;
    }

    /**
     * Every class declared in `src` whose `extends`/`implements` clause names
     * a member of the closure of `StateMachine`, with its file.
     *
     * @return array<class-string, string>
     */
    public static function machineFiles(): array
    {
        if (self::$machineFiles !== null) {
            return self::$machineFiles;
        }

        $declarations = [];

        foreach (self::files(['src']) as $file) {
            foreach (self::declarations($file) as $declaration) {
                $declarations[] = $declaration;
            }
        }

        $closure = ['StateMachine' => true];

        do {
            $grew = false;

            foreach ($declarations as [$class, , $parents]) {
                $short = substr($class, (int) strrpos($class, '\\') + 1);

                if (! isset($closure[$short]) && array_intersect_key(array_flip($parents), $closure) !== []) {
                    $closure[$short] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        $files = [];

        foreach ($declarations as [$class, $file, $parents]) {
            if (array_intersect_key(array_flip($parents), $closure) !== [] && class_exists($class)) {
                $files[$class] = $file;
            }
        }

        return self::$machineFiles = $files;
    }

    /**
     * The methods of {@see StateMachine} whose arguments are read, not written:
     * declared with a `bool` or `void` return type and at least one parameter.
     *
     * @return list<string> lower-cased
     */
    public static function guardNames(): array
    {
        if (self::$guardNames !== null) {
            return self::$guardNames;
        }

        $names = [];

        foreach ((new ReflectionClass(StateMachine::class))->getMethods() as $method) {
            $type = $method->getReturnType();

            if ($method->getNumberOfParameters() > 0
                && $type instanceof ReflectionNamedType
                && in_array($type->getName(), ['bool', 'void'], true)) {
                $names[] = strtolower($method->getName());
            }
        }

        sort($names);

        return self::$guardNames = $names;
    }

    /**
     * Every `function <guard name>` declared in a production file that is
     * neither a machine's nor the contract's own, as "file:line". The guard
     * rule reads a method call by its name alone, so it is safe only while
     * this is empty: then every method so named is a machine's.
     *
     * Read from tokens rather than by reflection, so that no production class
     * is loaded to answer it.
     *
     * @return list<string>
     */
    public static function guardNamesDeclaredOutsideTheMachines(): array
    {
        $own = [(string) realpath((string) (new ReflectionClass(StateMachine::class))->getFileName()) => true];

        foreach (self::machineFiles() as $file) {
            $own[(string) realpath($file)] = true;
        }

        $root = (string) realpath(self::ROOT);
        $outside = [];

        foreach (self::files(self::PRODUCTION) as $file) {
            $real = (string) realpath($file);

            if (isset($own[$real])) {
                continue;
            }

            $tokens = self::significant(PhpToken::tokenize((string) file_get_contents($file)));

            foreach ($tokens as $i => $token) {
                if ($token->is(T_FUNCTION)
                    && ($tokens[$i + 1] ?? null)?->is(T_STRING)
                    && in_array(strtolower($tokens[$i + 1]->text), self::guardNames(), true)) {
                    $outside[] = substr($real, strlen($root) + 1).':'.$token->line;
                }
            }
        }

        return $outside;
    }

    /**
     * Every `function withContext` declared in a production file, as
     * "file:line". The exception-context rule reads a method call by its name
     * alone, so it is safe only while this is exactly DomainException's.
     *
     * @return list<string>
     */
    public static function contextMethodDeclarations(): array
    {
        $root = (string) realpath(self::ROOT);
        $found = [];

        foreach (self::files(self::PRODUCTION) as $file) {
            $source = (string) file_get_contents($file);

            if (stripos($source, self::CONTEXT_METHOD) === false) {
                continue;
            }

            $tokens = self::significant(PhpToken::tokenize($source));

            foreach ($tokens as $i => $token) {
                if ($token->is(T_FUNCTION)
                    && ($tokens[$i + 1] ?? null)?->is(T_STRING)
                    && strtolower($tokens[$i + 1]->text) === self::CONTEXT_METHOD) {
                    $found[] = substr((string) realpath($file), strlen($root) + 1).':'.$token->line;
                }
            }
        }

        return $found;
    }

    /**
     * Every classified reference to an enum case in production code,
     * memoised for the process.
     *
     * @return array<string, list<array{string, int, string}>> "Enum::Case" → [file, line, position]
     */
    public static function sites(): array
    {
        if (self::$sites === null) {
            self::scan();
        }

        /** @var array<string, list<array{string, int, string}>> */
        return self::$sites;
    }

    /**
     * Every `Enum::from(...)` / `Enum::tryFrom(...)` in production code.
     *
     * @return array<class-string, list<array{string, int}>> FQCN → [file, line]
     */
    public static function constructions(): array
    {
        if (self::$constructions === null) {
            self::scan();
        }

        /** @var array<class-string, list<array{string, int}>> */
        return self::$constructions;
    }

    /**
     * The producer sites of each named case.
     *
     * @param  list<string>  $cases  "Enum::Case"
     * @return array<string, list<array{string, int, string}>>
     */
    public static function producersOf(array $cases): array
    {
        $producers = [];

        foreach ($cases as $case) {
            $producers[$case] = array_values(array_filter(
                self::sites()[$case] ?? [],
                static fn (array $site): bool => $site[2] === 'producer',
            ));
        }

        return $producers;
    }

    /** The number of classified sites in a reading position. */
    public static function readingSites(): int
    {
        $read = 0;

        foreach (self::sites() as $sites) {
            foreach ($sites as [, , $position]) {
                $read += $position === 'producer' ? 0 : 1;
            }
        }

        return $read;
    }

    /**
     * Classify one file's references, for the tests that pin the classifier
     * on a source they write themselves.
     *
     * @param  array<string, array<string, true>>  $enums  FQCN → case names of interest
     * @return array{0: list<array{string, int, string}>, 1: list<array{string, int}>}
     */
    public static function classifySource(string $source, array $enums): array
    {
        return self::classify($source, $enums);
    }

    private static function scan(): void
    {
        $enums = [];
        $skip = [];

        foreach (self::machineFiles() as $file) {
            $skip[(string) realpath($file)] = true;
        }

        foreach (self::enums() as $enum => $cases) {
            $enums[$enum] = array_fill_keys($cases, true);
        }

        $sites = [];
        $constructions = [];
        $root = (string) realpath(self::ROOT);

        foreach (self::files(self::PRODUCTION) as $file) {
            $real = (string) realpath($file);

            if (isset($skip[$real])) {
                continue;
            }

            $relative = substr($real, strlen($root) + 1);
            [$found, $built] = self::classify((string) file_get_contents($file), $enums);

            foreach ($found as [$state, $line, $position]) {
                $sites[$state][] = [$relative, $line, $position];
            }

            foreach ($built as [$enum, $line]) {
                $constructions[$enum][] = [$relative, $line];
            }
        }

        self::$sites = $sites;
        self::$constructions = $constructions;
    }

    /**
     * Class-like declarations in a file: [FQCN, file, short names of parents, kind].
     *
     * @return list<array{class-string, string, list<string>, string}>
     */
    public static function declarations(string $file): array
    {
        $tokens = self::significant(PhpToken::tokenize((string) file_get_contents($file)));
        $namespace = '';
        $found = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;

                continue;
            }

            if (! $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
                || ($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW])
                || ! ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                continue;
            }

            $parents = [];

            for ($j = $i + 2; isset($tokens[$j]) && $tokens[$j]->text !== '{'; $j++) {
                if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $parents[] = substr($tokens[$j]->text, (int) strrpos('\\'.$tokens[$j]->text, '\\'));
                }
            }

            /** @var class-string $class */
            $class = ltrim($namespace.'\\'.$tokens[$i + 1]->text, '\\');
            $found[] = [$class, $file, $parents, strtolower($token->text)];
        }

        return $found;
    }

    /**
     * Walk one source's tokens and classify every reference to a case of one
     * of the given enums, and every construction of one from a value.
     *
     * @param  array<string, array<string, true>>  $enums  FQCN → case names of interest
     * @return array{0: list<array{string, int, string}>, 1: list<array{string, int}>}
     */
    private static function classify(string $source, array $enums): array
    {
        $mentioned = false;
        foreach (array_keys($enums) as $enum) {
            if (str_contains($source, substr($enum, (int) strrpos($enum, '\\') + 1))) {
                $mentioned = true;
                break;
            }
        }

        if (! $mentioned) {
            return [[], []];
        }

        $tokens = self::significant(PhpToken::tokenize($source));
        $count = count($tokens);
        $tables = self::enumTransitionTables($tokens);

        $namespace = '';
        $aliases = [];
        $currentClass = null;

        /** @var list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}> $stack */
        $stack = [];
        $pendingBody = null;   // 'match' | 'switch' | 'class'
        $pendingConst = false;
        $init = null;          // stack depth at which an initialiser is open
        $found = [];
        $built = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $depth = count($stack);

            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;
                $aliases = [];

                continue;
            }

            if ($token->is(T_USE) && self::braceDepth($stack) === 0) {
                $i = self::readImports($tokens, $i, $aliases);

                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! $prev?->is(T_DOUBLE_COLON)) {
                $pendingBody = 'class';

                if (($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                    $currentClass = ltrim($namespace.'\\'.$tokens[$i + 1]->text, '\\');
                }

                continue;
            }

            if ($token->is(T_MATCH)) {
                $pendingBody = 'match';
            } elseif ($token->is(T_SWITCH)) {
                $pendingBody = 'switch';
            }

            if ($token->is(T_CONST) && ! $prev?->is(T_USE)) {
                $pendingConst = true;
            }

            if ($token->text === '=' && $init === null) {
                $top = $stack[$depth - 1] ?? null;

                if ($pendingConst || ($prev?->is(T_VARIABLE) && $top !== null && $top['kind'] === 'class')) {
                    $init = $depth;
                }

                $pendingConst = false;
            }

            if ($token->text === ';' && $init !== null && $depth === $init) {
                $init = null;
            }

            // Brackets.
            if ($token->text === '(') {
                $stack[] = self::frameForParen($tokens, $i, $stack);

                continue;
            }

            if ($token->text === '[' || $token->is(T_ATTRIBUTE)) {
                $isIndex = $token->is(T_ATTRIBUTE) || ($prev !== null && ($prev->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING]) || in_array($prev->text, [']', ')', '}'], true)));
                $stack[] = ['kind' => $isIndex ? 'index' : 'array', 'callee' => null, 'arg' => 0, 'arrow' => false, 'argOfParent' => self::argOf($stack)];

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $kind = $token->text === '{' && $pendingBody !== null ? $pendingBody : 'block';
                $pendingBody = null;
                $stack[] = ['kind' => $kind, 'callee' => null, 'arg' => 0, 'arrow' => false, 'argOfParent' => 0];

                continue;
            }

            if (in_array($token->text, [')', ']', '}'], true)) {
                array_pop($stack);

                continue;
            }

            if ($token->text === ',' && $depth > 0) {
                $stack[$depth - 1]['arg']++;
                $stack[$depth - 1]['arrow'] = false;

                continue;
            }

            if ($token->is(T_AS) && $depth > 0 && $stack[$depth - 1]['kind'] === 'foreach') {
                // What follows `as` is the loop variable, not the walked list.
                $stack[$depth - 1]['arg']++;

                continue;
            }

            if ($token->is(T_DOUBLE_ARROW) && $depth > 0) {
                $stack[$depth - 1]['arrow'] = true;

                continue;
            }

            // A reference: Name :: Case.
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])
                || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', ($tokens[$i + 2] ?? null)?->text ?? '') !== 1) {
                continue;
            }

            $enum = self::resolve($token, $namespace, $aliases, $currentClass);

            if (($tokens[$i + 3] ?? null)?->text === '(') {
                if ($enum !== null && isset($enums[$enum]) && in_array(strtolower($tokens[$i + 2]->text), ['from', 'tryfrom'], true)) {
                    $built[] = [$enum, $token->line];
                }

                continue;
            }

            $case = $tokens[$i + 2]->text;

            if ($enum === null || ! isset($enums[$enum][$case])) {
                continue;
            }

            $end = $i + 3;
            $projected = false;

            if (($tokens[$end] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $projected = true;
                $end += 2;
            }

            $found[] = [
                $enum.'::'.$case,
                $token->line,
                self::inRanges($i, $tables)
                    ? 'enum transition table'
                    : self::position($prev, $tokens[$end] ?? null, $projected, $stack, $init),
            ];

            $i = $end - 1;
        }

        return [$found, $built];
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private static function position(?PhpToken $prev, ?PhpToken $next, bool $projected, array $stack, ?int $init): string
    {
        $comparisons = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];

        if ($prev?->is(T_CASE)) {
            return 'switch case';
        }

        if ($prev?->is($comparisons) || $next?->is($comparisons)) {
            return 'comparison';
        }

        if ($projected && ! ($prev?->is([T_DOUBLE_ARROW, T_COALESCE_EQUAL]) || $prev?->text === '=')) {
            return 'scalar projection';
        }

        $depth = count($stack);
        $top = $stack[$depth - 1] ?? null;

        if ($top !== null && $top['kind'] === 'match' && ! $top['arrow']) {
            return 'match arm condition';
        }

        // An element of an array literal that is itself a match arm's
        // condition — `match ([$a, $b]) { [Enum::X, Enum::Y] => ... }` — is
        // compared, not stored: descended through array literals only.
        for ($m = $depth - 1; $m >= 0 && $stack[$m]['kind'] === 'array'; $m--);

        if ($m >= 0 && $m < $depth - 1 && $stack[$m]['kind'] === 'match' && ! $stack[$m]['arrow']) {
            return 'match arm condition';
        }

        if ($top !== null && $top['kind'] === 'call' && $top['callee'] !== null && str_starts_with($top['callee'], '->') && $top['callee'] !== '->'.self::CONTEXT_METHOD) {
            return 'transition guard argument';
        }

        // Anywhere inside the array given to an exception's withContext().
        for ($k = $depth - 1; $k >= 0 && $stack[$k]['kind'] === 'array'; $k--);

        if ($k >= 0 && $k < $depth - 1 && $stack[$k]['kind'] === 'call' && $stack[$k]['callee'] === '->'.self::CONTEXT_METHOD) {
            return 'exception context';
        }

        // The array literals directly enclosing the reference, innermost first.
        $arrays = 0;
        while ($arrays < $depth && $stack[$depth - 1 - $arrays]['kind'] === 'array') {
            $arrays++;
        }

        $outer = $stack[$depth - 1 - $arrays] ?? null;

        if ($arrays > 0
            && $outer !== null
            && $outer['kind'] === 'call'
            && in_array($outer['callee'], self::MEMBERSHIP_PREDICATES, true)
            && $stack[$depth - $arrays]['argOfParent'] === 1) {
            return 'haystack member';
        }

        if ($arrays > 0
            && $outer !== null
            && $outer['kind'] === 'foreach'
            && $stack[$depth - $arrays]['argOfParent'] === 0
            && ! $top['arrow']) {
            return 'walked list member';
        }

        if ($init !== null && $depth - $arrays === $init) {
            if ($arrays === 0 || $top['arrow']) {
                return 'producer';
            }

            return 'initialiser list member';
        }

        return 'producer';
    }

    /**
     * The token ranges (bodies, braces included) of an enum's own transition
     * table: in each enum declared in the source, the body of a method named
     * `canBecome` whose declared return type is exactly `bool`, and the body
     * of every `private` method of the same enum that is called at least once
     * and only from inside such a body (called as `$this->name(`,
     * `self::name(` or `static::name(`; one level, not transitively).
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{int, int}>
     */
    private static function enumTransitionTables(array $tokens): array
    {
        $count = count($tokens);
        $ranges = [];

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is(T_ENUM)
                || ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)
                || ! ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                continue;
            }

            for ($open = $i; $open < $count && $tokens[$open]->text !== '{'; $open++);
            $close = self::closingBrace($tokens, $open);

            $methods = [];

            for ($j = $open + 1; $j < $close; $j++) {
                if (! $tokens[$j]->is(T_FUNCTION) || ! ($tokens[$j + 1] ?? null)?->is(T_STRING)) {
                    continue;
                }

                $private = false;
                for ($k = $j - 1; $k > $open && $tokens[$k]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT]); $k--) {
                    $private = $private || $tokens[$k]->is(T_PRIVATE);
                }

                for ($body = $j; $body < $close && $tokens[$body]->text !== '{' && $tokens[$body]->text !== ';'; $body++);

                if ($tokens[$body]->text !== '{') {
                    continue;
                }

                // The return type: the tokens between the parameter list's `)` and the body.
                $type = '';
                for ($k = $body - 1; $k > $j && $tokens[$k]->text !== ')'; $k--) {
                    $type = $tokens[$k]->text.$type;
                }

                $end = self::closingBrace($tokens, $body);
                $methods[strtolower($tokens[$j + 1]->text)] = ['private' => $private, 'type' => ltrim($type, ':'), 'range' => [$body, $end]];
                $j = $end;
            }

            $tables = [];

            foreach ($methods as $name => $method) {
                if ($name === 'canbecome' && strtolower($method['type']) === 'bool') {
                    $tables[] = $method['range'];
                }
            }

            if ($tables === []) {
                $i = $close;

                continue;
            }

            foreach ($methods as $name => $method) {
                if (! $method['private'] || $name === 'canbecome') {
                    continue;
                }

                $calls = [];

                for ($k = $open + 1; $k < $close; $k++) {
                    if (strtolower($tokens[$k]->text) === $name
                        && ($tokens[$k + 1] ?? null)?->text === '('
                        && (($tokens[$k - 1]->is(T_OBJECT_OPERATOR) && ($tokens[$k - 2] ?? null)?->text === '$this')
                            || ($tokens[$k - 1]->is(T_DOUBLE_COLON) && in_array(strtolower(($tokens[$k - 2] ?? null)?->text ?? ''), ['self', 'static'], true)))) {
                        $calls[] = $k;
                    }
                }

                if ($calls !== [] && array_filter($calls, static fn (int $k): bool => ! self::inRanges($k, $tables)) === []) {
                    $ranges[] = $method['range'];
                }
            }

            array_push($ranges, ...$tables);
            $i = $close;
        }

        return $ranges;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function closingBrace(array $tokens, int $open): int
    {
        $count = count($tokens);
        $level = 0;

        for ($k = $open; $k < $count; $k++) {
            if ($tokens[$k]->text === '{' || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $level++;
            } elseif ($tokens[$k]->text === '}' && --$level === 0) {
                return $k;
            }
        }

        return $count - 1;
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private static function inRanges(int $i, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($i > $from && $i < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     * @return array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}
     */
    private static function frameForParen(array $tokens, int $i, array $stack): array
    {
        $prev = $tokens[$i - 1] ?? null;
        $before = $tokens[$i - 2] ?? null;
        $frame = ['kind' => 'group', 'callee' => null, 'arg' => 0, 'arrow' => false, 'argOfParent' => self::argOf($stack)];

        if ($prev?->is(T_ARRAY)) {
            $frame['kind'] = 'array';
        } elseif ($prev?->is(T_FOREACH)) {
            $frame['kind'] = 'foreach';
        } elseif ($prev?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $frame['kind'] = 'call';

            if ($before?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                // A method call is never a membership predicate; a transition
                // guard is recognised by its name alone, which is safe only
                // while guardNamesDeclaredOutsideTheMachines() is empty.
                if (in_array(strtolower($prev->text), [...self::guardNames(), self::CONTEXT_METHOD], true)) {
                    $frame['callee'] = '->'.strtolower($prev->text);
                }
            } elseif (! $before?->is([T_DOUBLE_COLON, T_FUNCTION])) {
                // A static call is never a recognised predicate, and a
                // declaration is not a call at all.
                $frame['callee'] = strtolower(ltrim($prev->text, '\\'));
            }
        } elseif ($prev !== null && ($prev->is(T_VARIABLE) || in_array($prev->text, [')', ']', '}'], true))) {
            $frame['kind'] = 'call';
        }

        return $frame;
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private static function argOf(array $stack): int
    {
        return $stack === [] ? 0 : $stack[count($stack) - 1]['arg'];
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private static function braceDepth(array $stack): int
    {
        return count(array_filter($stack, static fn (array $frame): bool => ! in_array($frame['kind'], ['group', 'call', 'array', 'index', 'foreach'], true)));
    }

    /**
     * Read a top-level `use` statement into the alias map; returns the index
     * of its terminating `;`.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function readImports(array $tokens, int $i, array &$aliases): int
    {
        $prefix = '';
        $name = null;
        $alias = null;
        $grouped = false;

        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $t = $tokens[$j];

            if ($t->is([T_FUNCTION, T_CONST])) {
                // `use function` / `use const` import no classes.
                while (isset($tokens[$j]) && $tokens[$j]->text !== ';') {
                    $j++;
                }

                return $j;
            }

            if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if (($tokens[$j - 1] ?? null)?->is(T_AS)) {
                    $alias = $t->text;
                } else {
                    $name = ltrim($t->text, '\\');
                }

                continue;
            }

            if ($t->is(T_NS_SEPARATOR) && ($tokens[$j + 1] ?? null)?->text === '{') {
                $prefix = (string) $name.'\\';
                $name = null;

                continue;
            }

            if ($t->text === '{') {
                $grouped = true;

                continue;
            }

            if (in_array($t->text, [',', '}', ';'], true)) {
                if ($name !== null) {
                    $full = ($grouped ? $prefix : '').$name;
                    $aliases[$alias ?? substr($full, (int) strrpos('\\'.$full, '\\'))] = $full;
                }

                $name = null;
                $alias = null;

                if ($t->text === ';') {
                    return $j;
                }
            }
        }

        return $j;
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function resolve(PhpToken $token, string $namespace, array $aliases, ?string $currentClass): ?string
    {
        $name = $token->text;

        if (in_array(strtolower($name), ['self', 'static'], true)) {
            return $currentClass;
        }

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];

        if (isset($aliases[$first])) {
            return $aliases[$first].substr($name, strlen($first));
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private static function significant(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
        ));
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    public static function files(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
