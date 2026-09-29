<?php

declare(strict_types=1);

namespace Tests\Architecture;

use BackedEnum;
use FilesystemIterator;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Contracts\StateMachine;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use SplFileInfo;

/**
 * Every state a machine declares it can enter is one some production code can
 * put a row into — or it is named below with the reason nothing does.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * The architecture suite already asks the reachability question of methods,
 * events, capabilities, metrics and translations. It never asked it of the two
 * things that carry state: enum cases and machine states. The result was a
 * translation gate ({@see EveryStateAScreenShowsIsTranslatedTest}) that walks
 * `$enum::cases()` and demands an English and an Arabic string for every case —
 * including cases no transition can produce — and a suite that is green while
 * nothing establishes a customer can ever see them.
 *
 * A transition table declares that a move is **legal**. That is exactly not
 * evidence that anything performs it. The first time this gate ran, with an
 * empty allow-list, on a tree it had never been told about, it named
 * `DedicatedServerStatus::Retired` (F-47's subject) without being pointed at
 * it, `InvoiceStatus::Uncollectible` — a state `SettleInvoice::PAYABLE` lists
 * as one that can still take money — and nine of `OrderStatus`'s thirteen
 * cases, which is the audit's own headline reproduced by an instrument that
 * had never read it.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * **Subjects** are discovered, not listed. Every concrete class in `src` that
 * implements {@see StateMachine}, found by closing the name `StateMachine`
 * transitively over every `extends`/`implements` clause in `src` to a
 * fixpoint — today ten names: the contract, the abstract base, and eight
 * machines. The closure matters in both halves of the gate: an interface
 * `Lifecycle extends StateMachine` with a machine naming only `Lifecycle`
 * would otherwise go undiscovered, **and** — the worse half — an undiscovered
 * machine is also absent from the producer scan's skip list, so its own
 * transition table would count as production code writing its states and a
 * dead state would be concealed rather than missed. Only the files the closure
 * names are loaded; this file declines to move another architecture test's
 * subject (the set of declared classes) in either direction.
 *
 * **States** are each machine's own `transitions()` targets: a case that is a
 * legal destination of some edge.
 *
 * **Producers** are found by tokenising (`PhpToken`, not a regex) every PHP
 * file under `src` and `app`, **except the machines' own files** — a table
 * says what is legal, which is precisely what is not being asked. Test code,
 * factories and seeders are not production and are not read. A reference to
 * a case, `Enum::Case` (or `self::Case` inside the enum), counts as a
 * producer unless it stands in one of these reading positions:
 *
 *  - an operand of `===`, `!==`, `==` or `!=`;
 *  - a condition of a `match` arm (left of its `=>`);
 *  - a `case` label of a `switch`;
 *  - a member of a set that is being DECLARED or TESTED — the concept, at
 *    four named positions: an element of a list literal initialising a
 *    `const`; an element of a list literal initialising a property
 *    (`private array $x = [...]`); an element of the haystack literal of
 *    `in_array()`/`array_search()`, long form `array(...)` included and
 *    nested literals descended; and the `switch` label above.
 *
 * At the two initialiser positions a **keyed** value is not a member: in
 * `protected $attributes = ['status' => Enum::X]` Eloquent writes the value
 * into every new row, and in `const D = ['status' => Enum::X]` the same
 * value is one `self::D` away from the same place. So list elements are a
 * declared set and keyed values are producers, at both positions alike; a case
 * standing alone as an initialiser (`const X = Enum::Y`) is a named value, and
 * a producer.
 *
 * `MEMBERSHIP_PREDICATES` is closed to `in_array` and `array_search` by a
 * stated safety condition: the callee must be unable to write through the
 * argument **and** unable to return an element of it. That is why
 * `array_merge` and `array_replace` are absent, and why a method call is
 * never recognised however it is spelled. **An unrecognised callee is treated
 * as a producer** — the conservative direction, which risks a dead state
 * staying hidden and never calls a live state dead. Inside a haystack a call
 * or an index is a boundary: `[wrap(Enum::A)]` and `[$map[Enum::A], Enum::B]`
 * each keep the wrapped or indexed reference a producer.
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
 * ===========================================================================
 * WHAT IT DOES NOT SEE
 * ===========================================================================
 *
 *  - **Strings.** `'status' => 'cancelled'`, `Enum::from($x)` and
 *    `Enum::tryFrom($x)` are invisible in both directions. A write spelled as
 *    a string is not counted, and a reader spelled as a string — such as
 *    `ReapExpiredReservations::TERMINAL_FAILURE_STATUSES` — is not either.
 *  - **A `->value` write that is not assignment-shaped**, for instance a
 *    scalar passed positionally to a setter, reads as a read.
 *  - **A read that is assignment-shaped reads as a write.**
 *    `where('status', '!=', $excluded = Enum::X->value)` and Laravel's
 *    array-form `where(['status' => Enum::X->value])` are both reads, and
 *    both are counted as producers. The property cannot be closed inside a
 *    token classifier: the obvious fix would also stop counting
 *    `insertOrIgnore(['status' => Enum::X->value])`, which is a write whose
 *    array is equally an argument to a query-builder call. So when this gate
 *    goes red saying an excused state now has a writer, establish which kind
 *    of red it is first: **if the only change near the site is how the
 *    scalar is spelled at a query, revert the spelling alone; a real writer
 *    survives that.**
 *  - **A bare case given to a query builder** — `where('status', Enum::X)` —
 *    counts as a producer. That over-counts, in the conservative direction.
 *  - **Nested literal shapes beyond arrays.** A haystack is descended through
 *    array literals only; anything else inside it is a boundary.
 *  - **Reachability of the writer itself.** A producer is a site that names
 *    the case in a writing position. Whether that site's method is ever
 *    called is {@see NoDeadMethodsTest}'s question, not this one.
 */
final class EveryStateAMachineCanEnterHasAProducerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** @var list<string> */
    private const array PRODUCTION = ['src', 'app'];

    /**
     * Callees whose array argument is a haystack: they can neither write
     * through it nor return an element of it.
     *
     * @var list<string>
     */
    private const array MEMBERSHIP_PREDICATES = ['in_array', 'array_search'];

    /**
     * States a machine can legally enter that no production code produces,
     * each with the reason and the owner of the decision.
     *
     * An entry is an admission, not a fix. It is removed when the state gains
     * a writer (the second test fails until it is), or when the owner decides
     * to delete the case (the second test fails then too, because the entry
     * outlives the state it excused). Either way the decision cannot be taken
     * silently.
     *
     * `DedicatedServerStatus::Retired` — the state this gate found on its own
     * the first time it ran, and F-47's subject — is not here: F-12's
     * `RetireDedicatedServer` writes it. That is the event this gate was armed
     * for, and the entry went when the writer arrived.
     *
     * Nor are the nine `OrderStatus` cases the audit's F-19 headline named
     * (payment_failed, queued_for_provisioning, provisioning,
     * provisioning_failed, manual_review, active, suspended, refunded,
     * terminated). They were excused here, each owned by F-19, until F-19
     * gave every one of them a production writer; the second test then
     * failed on all nine, as designed, and the entries went.
     *
     * @var array<string, string>
     */
    private const array UNPRODUCED = [
        /*
         * Written off after dunning has given up, says the table, and
         * `uncollectible → paid` and `→ void` are both legal. Nothing gives up:
         * no action, job or command writes the state, so no invoice reaches it.
         * It still has a reader that matters — `SettleInvoice::PAYABLE` lists it
         * as a status that can take money — so a documented money branch is one
         * no invoice can enter. Building a write-off is new billing capability
         * outside this programme; whether to build it, delete the case or
         * declare it prepared belongs to the Billing module's owner.
         */
        InvoiceStatus::class.'::Uncollectible' => 'No write-off exists: nothing moves an open invoice to uncollectible, and SettleInvoice::PAYABLE reads it. Billing owns the decision.',

        /*
         * The table's own comment calls it "give up": a legal target from
         * `queued` and from `needs_review`, terminal, read by
         * `AdoptOrphanResource` as a settled status that blocks adoption, and
         * written only by tests. Giving up on a provisioning job is an operator
         * capability the platform describes and does not have; building it is
         * new capability outside this programme, and the choice between
         * deleting the case, wiring a cancel action and declaring the state
         * prepared belongs to the Provisioning module's owner, not to this gate.
         *
         * A fifth reader raises the cost of the "just delete the case" option:
         * `ReapExpiredReservations.php:71` declares TERMINAL_FAILURE_STATUSES =
         * ['failed', 'cancelled'] and `:104` uses it in a `whereIn` over
         * `provisioning_jobs` to decide which address reservations to reclaim.
         * Being strings, it is invisible to this classifier in both directions:
         * delete the case and a string constant would still name a status the
         * enum no longer has, and nothing would say so.
         */
        ProvisioningJobStatus::class.'::Cancelled' => 'No operator action cancels a provisioning job; AdoptOrphanResource.php:134 and ReapExpiredReservations.php:71 read it. Provisioning owns the decision.',
    ];

    /** @var array<string, list<array{string, int, string}>>|null */
    private static ?array $sites = null;

    #[Test]
    public function every_state_a_machine_can_enter_is_written_by_production_code(): void
    {
        $destinations = $this->destinations();
        $producers = $this->producers();

        $this->assertGuards($destinations, $producers);

        $unwritten = [];

        foreach ($destinations as $state => $sources) {
            if (isset(self::UNPRODUCED[$state]) || $producers[$state] !== []) {
                continue;
            }

            $unwritten[] = sprintf('%s — a legal target from {%s}', $state, implode(', ', $sources));
        }

        $this->assertSame([], $unwritten, sprintf(
            "These states are legal transition targets that no production code in src/ or app/ writes:\n  %s\n\n".
            'A transition table says a move is legal; it is not evidence anything performs it. Either give the '.
            'state a writer, delete the case, or add it to UNPRODUCED with the reason and the owner of the decision.',
            implode("\n  ", $unwritten),
        ));
    }

    #[Test]
    public function no_excuse_outlives_the_state_it_excuses(): void
    {
        $destinations = $this->destinations();
        $producers = $this->producers();

        $this->assertGuards($destinations, $producers);

        $stale = [];

        foreach (array_keys(self::UNPRODUCED) as $state) {
            if (! isset($destinations[$state])) {
                $stale[] = "{$state} — no longer a legal target of any machine (or no longer a case at all)";

                continue;
            }

            foreach ($producers[$state] as [$file, $line, $position]) {
                $stale[] = "{$state} — written at {$file}:{$line} ({$position})";
            }
        }

        $this->assertSame([], $stale, sprintf(
            "These UNPRODUCED entries excuse a state that no longer needs excusing:\n  %s\n\n".
            'Remove the entry. If the new writer is only a query that changed how it spells the scalar, it is a '.
            'read this classifier cannot tell from a write: revert the spelling alone, and a real writer survives that.',
            implode("\n  ", $stale),
        ));
    }

    /**
     * The translation gate's enum list is written by hand, so an enum is
     * covered only if somebody remembers to add it. A machine's enum is the
     * one kind of enum this file can name without a definition of "rendered"
     * to argue about: every one of them is a status column a screen shows. So
     * each must be an entry of that gate's `RENDERED` — the entry, read by
     * reflection, not the `use` line, which survives the entry's removal.
     */
    #[Test]
    public function every_enum_a_state_machine_governs_is_named_by_the_translation_gate(): void
    {
        $governed = [];

        foreach ($this->machines() as $machine) {
            foreach ($machine->transitions() as $targets) {
                foreach ($targets as $target) {
                    $governed[$target::class] = true;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            8,
            count($governed),
            'Fewer machine enums were found than exist today; discovery is broken, and a gate over nothing passes for the wrong reason.',
        );

        $constant = new ReflectionClassConstant(EveryStateAScreenShowsIsTranslatedTest::class, 'RENDERED');
        $rendered = $constant->getValue();

        $this->assertIsArray($rendered, 'RENDERED is not an array; this test would be reading nothing.');
        $this->assertNotSame([], $rendered, 'RENDERED is empty; this test would be reading nothing.');

        $named = [];

        foreach ($rendered as $enums) {
            foreach ((array) $enums as $enum) {
                $named[$enum] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($governed), array_keys($named)));
        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These enums are governed by a state machine and are not an entry in %s::RENDERED, so a case added to\n".
            "one can reach a screen with no string in either language:\n  %s",
            EveryStateAScreenShowsIsTranslatedTest::class,
            implode("\n  ", $missing),
        ));
    }

    /**
     * Vacuity guards, in each test that reads the classifier, because a test
     * run alone under `--filter` or in its own process must not pass over
     * nothing.
     *
     * @param  array<string, list<string>>  $destinations
     * @param  array<string, list<array{string, int, string}>>  $producers
     */
    private function assertGuards(array $destinations, array $producers): void
    {
        $this->assertGreaterThanOrEqual(8, count($this->machines()), 'Fewer state machines were discovered than exist today.');
        $this->assertNotSame([], $destinations, 'No machine declared a destination; discovery is broken.');

        $written = array_filter($producers, static fn (array $sites): bool => $sites !== []);
        $this->assertGreaterThan(
            count($destinations) / 2,
            count($written),
            'Fewer than half of all destinations have a producer; the classifier is almost certainly broken rather than the tree.',
        );

        $read = 0;
        foreach (self::sites() as $sites) {
            foreach ($sites as [, , $position]) {
                $read += $position === 'producer' ? 0 : 1;
            }
        }

        $this->assertGreaterThan(0, $read, 'The classifier recognised no reading position at all; it is not classifying.');
    }

    /**
     * @return array<string, list<string>> "Enum::Case" → the source states it is a target from
     */
    private function destinations(): array
    {
        $destinations = [];

        foreach ($this->machines() as $machine) {
            foreach ($machine->transitions() as $from => $targets) {
                foreach ($targets as $target) {
                    $destinations[$target::class.'::'.$target->name][] = (string) $from;
                }
            }
        }

        ksort($destinations);

        return $destinations;
    }

    /**
     * @return array<string, list<array{string, int, string}>> every destination → its producer sites
     */
    private function producers(): array
    {
        $producers = [];

        foreach (array_keys($this->destinations()) as $state) {
            $producers[$state] = array_values(array_filter(
                self::sites()[$state] ?? [],
                static fn (array $site): bool => $site[2] === 'producer',
            ));
        }

        return $producers;
    }

    /**
     * @return list<StateMachine<BackedEnum>>
     */
    private function machines(): array
    {
        $machines = [];

        foreach ($this->machineFiles() as $class => $file) {
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
     * Every class declared in `src` whose `extends`/`implements` clause names
     * a member of the closure of `StateMachine`, with its file.
     *
     * @return array<class-string, string>
     */
    private function machineFiles(): array
    {
        $declarations = [];

        foreach ($this->files(['src']) as $file) {
            foreach ($this->declarations($file) as $declaration) {
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

        return $files;
    }

    /**
     * Class-like declarations in a file: [FQCN, file, short names of parents].
     *
     * @return list<array{class-string, string, list<string>}>
     */
    private function declarations(string $file): array
    {
        $tokens = $this->significant(PhpToken::tokenize((string) file_get_contents($file)));
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
            $found[] = [$class, $file, $parents];
        }

        return $found;
    }

    /**
     * Every classified reference to a machine state in production code,
     * memoised for the process. Each test asserts its own guards over it.
     *
     * @return array<string, list<array{string, int, string}>> "Enum::Case" → [file, line, position]
     */
    private static function sites(): array
    {
        if (self::$sites !== null) {
            return self::$sites;
        }

        $test = new self('sites');
        $enums = [];
        $skip = [];

        foreach ($test->machineFiles() as $file) {
            $skip[realpath($file)] = true;
        }

        foreach (array_keys($test->destinations()) as $state) {
            [$enum, $case] = explode('::', $state);
            $enums[$enum][$case] = true;
        }

        $sites = [];

        foreach ($test->files(self::PRODUCTION) as $file) {
            if (isset($skip[realpath($file)])) {
                continue;
            }

            foreach ($test->classify($file, $enums) as [$state, $line, $position]) {
                $sites[$state][] = [substr((string) realpath($file), strlen((string) realpath(self::ROOT)) + 1), $line, $position];
            }
        }

        return self::$sites = $sites;
    }

    /**
     * Walk one file's tokens and classify every reference to a case of one of
     * the given enums.
     *
     * @param  array<string, array<string, true>>  $enums  FQCN → case names of interest
     * @return list<array{string, int, string}> ["Enum::Case", line, position]
     */
    private function classify(string $file, array $enums): array
    {
        $source = (string) file_get_contents($file);

        $mentioned = false;
        foreach (array_keys($enums) as $enum) {
            if (str_contains($source, substr($enum, (int) strrpos($enum, '\\') + 1))) {
                $mentioned = true;
                break;
            }
        }

        if (! $mentioned) {
            return [];
        }

        $tokens = $this->significant(PhpToken::tokenize($source));
        $count = count($tokens);

        $namespace = '';
        $aliases = [];
        $currentClass = null;

        /** @var list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}> $stack */
        $stack = [];
        $pendingBody = null;   // 'match' | 'switch' | 'class'
        $pendingConst = false;
        $init = null;          // stack depth at which an initialiser is open
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $depth = count($stack);

            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;
                $aliases = [];

                continue;
            }

            if ($token->is(T_USE) && $this->braceDepth($stack) === 0) {
                $i = $this->readImports($tokens, $i, $aliases);

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
                $stack[] = $this->frameForParen($tokens, $i, $stack);

                continue;
            }

            if ($token->text === '[' || $token->is(T_ATTRIBUTE)) {
                $isIndex = $token->is(T_ATTRIBUTE) || ($prev !== null && ($prev->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING]) || in_array($prev->text, [']', ')', '}'], true)));
                $stack[] = ['kind' => $isIndex ? 'index' : 'array', 'callee' => null, 'arg' => 0, 'arrow' => false, 'argOfParent' => $this->argOf($stack)];

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

            if ($token->is(T_DOUBLE_ARROW) && $depth > 0) {
                $stack[$depth - 1]['arrow'] = true;

                continue;
            }

            // A reference: Name :: Case, not followed by a call.
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])
                || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                || ! ($tokens[$i + 2] ?? null)?->is(T_STRING)
                || ($tokens[$i + 3] ?? null)?->text === '(') {
                continue;
            }

            $enum = $this->resolve($token, $namespace, $aliases, $currentClass);
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
                $this->position($prev, $tokens[$end] ?? null, $projected, $stack, $init),
            ];

            $i = $end - 1;
        }

        return $found;
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private function position(?PhpToken $prev, ?PhpToken $next, bool $projected, array $stack, ?int $init): string
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

        if ($init !== null && $depth - $arrays === $init) {
            if ($arrays === 0 || $top['arrow']) {
                return 'producer';
            }

            return 'initialiser list member';
        }

        return 'producer';
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     * @return array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}
     */
    private function frameForParen(array $tokens, int $i, array $stack): array
    {
        $prev = $tokens[$i - 1] ?? null;
        $before = $tokens[$i - 2] ?? null;
        $frame = ['kind' => 'group', 'callee' => null, 'arg' => 0, 'arrow' => false, 'argOfParent' => $this->argOf($stack)];

        if ($prev?->is(T_ARRAY)) {
            $frame['kind'] = 'array';
        } elseif ($prev?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $frame['kind'] = 'call';

            // A method or static call is never a recognised predicate, and a
            // declaration is not a call at all.
            if (! $before?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
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
    private function argOf(array $stack): int
    {
        return $stack === [] ? 0 : $stack[count($stack) - 1]['arg'];
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private function braceDepth(array $stack): int
    {
        return count(array_filter($stack, static fn (array $frame): bool => ! in_array($frame['kind'], ['group', 'call', 'array', 'index'], true)));
    }

    /**
     * Read a top-level `use` statement into the alias map; returns the index
     * of its terminating `;`.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private function readImports(array $tokens, int $i, array &$aliases): int
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
    private function resolve(PhpToken $token, string $namespace, array $aliases, ?string $currentClass): ?string
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
    private function significant(array $tokens): array
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
    private function files(array $directories): array
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
