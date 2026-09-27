<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Tests\Support\UnpinnedClockAssertions;

/**
 * No test compares a value with a clock read made at the assertion while the
 * clock is free to move.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * F-44. The code under test stores a time from the clock — a quarantine that
 * ends seven days from now, a node's `reconciled_at` — and the test then reads
 * the clock again inside the assertion: `assertSame(now()->addDays(7)
 * ->toDateString(), $ip->quarantined_until->toDateString())`. The two reads
 * are a few milliseconds apart, and when those milliseconds straddle a second
 * (or, for a date, midnight) the test is red for a run and green for the next.
 * The audit found it in `QuarantineLifecycleTest`; round three found it in
 * `DecommissioningGivesTheAddressBackTest`; round five in
 * `HeldQuarantineClockTest`; and round six wrote it again, in
 * `HostingReconciliationTest` (red once in the full suite at `031f6c9`:
 * 1790505192 against 1790505193; fixed at `02d7364`). Every one was held by
 * an edit and nothing held the shape. This gate does.
 *
 * The cure never weakens the claim: pin the clock before the code runs
 * (`$this->freezeSecond()`), so the code's read and the assertion's read are
 * the same instant; or, where the code stores a time it was given, assert
 * that input rather than the clock. Reading an unpinned clock once into a
 * variable is not a cure: the code reads the clock later than the variable
 * does, and the two can still straddle a second or a midnight.
 *
 * ===========================================================================
 * WHAT IT READS, AND WHAT IT CANNOT SEE
 * ===========================================================================
 *
 * Every `*.php` file under `tests/` (so not the `.php.txt` fixtures), handed
 * to {@see UnpinnedClockAssertions}. Its docblock is the exact statement of
 * what counts as a test, a pin, an assertion, a clock read and a flow, and of
 * what it does not see; those are this gate's limits. In short: it walks each
 * test in source order from the pin state its set-up leaves (the class's,
 * its parents' and its traits', within `tests/`), follows `$this->helper()`
 * calls into code in `tests/`, and reports an equality assertion — or an
 * `assertTrue`/`assertFalse` of an equality — whose compared value is made
 * from a clock read where the clock is not pinned. PHP's own clock
 * (`time()`, `date()`, `new DateTime()`) is reported pinned or not, because
 * freezing does not reach it.
 *
 * It does **not** follow a clock read through a variable, whatever the
 * variable holds. A variable read on an unpinned clock and never handed to
 * the code — read before the code ran or after it — is the shape, and is
 * not seen. Measured at `02d7364` with variables followed, the walk
 * reported five more tests (`ApiTokenIssuanceTest`,
 * `RowsAlreadyInFlightKeepTheirOwnClockTest`, `ServerDatesAreTheCustomersTest`
 * twice, `HostingUsageEndpointTest`), and in every one the variable is the
 * test's own read handed to the code as input (an expiry posted, a column
 * written by the test) and compared with what the code kept of it: an
 * assertion about the input, not about the clock. Following variables would
 * have reported only those; not following them leaves the other kind unseen. A
 * bound comparison with a tolerance (`assertLessThanOrEqual`,
 * `assertEqualsWithDelta`, `->lessThan(now())`) is not the shape either: it
 * does not change its answer when a second or a day turns over.
 *
 * ===========================================================================
 * WHAT HOLDS THE GATE ITSELF
 * ===========================================================================
 *
 *  - **Controls.** `Fixtures/clock-reads-at-assertions.php.txt` holds tests
 *    named `found_*` (each must be reported) and `clean_*` (none may be), and
 *    the scanner's verdict on each is compared exactly. What that holds is
 *    exactly this: every element of the constants listed in
 *    {@see UnpinnedClockAssertions::VOCABULARY} (and every compared position
 *    of every {@see UnpinnedClockAssertions::EQUALITY} entry) and every
 *    behaviour named in {@see self::STRUCTURAL} has its own control, so a
 *    change to the scanner that alters its verdict on one of them turns the
 *    gate red. A behaviour in neither has no control and is not claimed to
 *    be held.
 *     - Element controls are named `<found|clean|ignored>_<prefix>__<element>`
 *       (`ignored`: a method that must not be read as a test), or, for a
 *       hook name, are a `#[Test]` method named the element itself; and
 *       {@see self::every_element_the_scanner_recognises_has_its_own_control()}
 *       fails when an element has no control, when a control names an
 *       element no longer listed, when a public list constant is missing
 *       from the vocabulary, when a {@see self::STRUCTURAL} control is
 *       missing, or when two controls share a name.
 *     - Structural controls are hand-written, one per behaviour the
 *       scanner's code (rather than a constant) decides: how tests, set-up
 *       and helpers are found and resolved, what the walk visits and in what
 *       order, what flows and what does not, the literal rules, and the
 *       `031f6c9` and `e0aa0ab` shapes. Methods that must *not* be read as
 *       tests are named `ignored_*` (or, for a hook name, the hook's own
 *       name), outside `found_`/`clean_`, so reading one as a test adds a
 *       finding or a test the exact comparison refuses.
 *     - The file a finding names (the helper's, not the test's) needs two
 *       sources, so {@see self::a_finding_in_a_helper_names_the_file_that_holds_it()}
 *       holds it.
 *  - **Excuses.** A test may be excused only in {@see self::EXCUSES}, with its
 *    reason, and an excuse whose test no longer has the shape fails the gate:
 *    none outlives its reason.
 *  - **Reach.** The walk must read thousands of tests and see pins in them;
 *    a parser that silently read nothing would otherwise pass.
 *
 * Red before: restoring `031f6c9`'s `HostingReconciliationTest`,
 * `e0aa0ab`'s `HeldQuarantineClockTest`, `a0462ca^`'s
 * `QuarantineLifecycleTest` or `05e32b4^`'s
 * `DecommissioningGivesTheAddressBackTest` turns the first test red, naming
 * each method that carried the shape.
 */
final class NoAssertionComparesAClockReadOnAnUnpinnedClockTest extends TestCase
{
    private const string FIXTURE = 'tests/Architecture/Fixtures/clock-reads-at-assertions.php.txt';

    /**
     * Tests allowed to keep the shape, as `Class::method` => why. Empty: at
     * `02d7364` the walk reports no test in the suite.
     *
     * @var array<string, string>
     */
    private const array EXCUSES = [];

    /**
     * The hand-written controls, each holding a claim of the scanner's
     * docblock that no constant carries. Each must exist in the fixture.
     *
     * @var array<string, string>
     */
    private const array STRUCTURAL = [
        'found_the_reconciliation_stamp' => 'the 031f6c9 shape',
        'found_the_quarantine_date' => 'the e0aa0ab shape',
        'found_an_empty_literal' => 'a blank literal is relative',
        'found_a_relative_phrase' => 'a relative literal to a static parser',
        'found_a_relative_phrase_in_a_constructor' => 'a relative literal to a constructor',
        'found_a_native_relative_date_time_even_frozen' => 'a relative literal to a native constructor, pinned',
        'clean_an_unreadable_literal' => 'a literal strtotime() cannot read is not relative',
        'clean_not_a_clock_read' => 'absolute literals and non-literals are not relative',
        'clean_create_and_make_with_nothing' => 'create() and make() with nothing are not clock reads',
        'clean_is_same_day_with_nothing' => 'isSame* is not implicit',
        'clean_a_native_function_given_a_timestamp' => 'a native function at another arity is not a clock read',
        'found_through_a_function_argument' => 'a plain function\'s arguments flow',
        'found_through_a_clock_static_argument' => 'a clock class\'s static call\'s arguments flow',
        'clean_through_another_static_argument' => 'no other call\'s arguments flow',
        'found_with_named_arguments' => 'a named argument other than message is compared',
        'clean_a_clock_read_only_in_the_message' => 'a message is never compared',
        'clean_bound_comparisons' => 'bound comparisons are not findings',
        'clean_a_clock_read_as_a_query_bound' => 'a query bound is not a flow',
        'clean_read_once_in_a_variable' => 'a variable is not followed',
        'found_before_the_pin' => 'the walk is in source order',
        'found_after_a_frozen_callback_returns' => 'the clock is unpinned after a pin callback',
        'clean_inside_a_frozen_callback' => 'a pin callback is walked pinned',
        'found_after_with_test_now_returns' => 'a test-now scope restores the clock it found',
        'found_after_set_test_now_is_cleared' => 'a test-now setter given null unpins',
        'found_after_a_closure_that_freezes' => 'a closure\'s pin does not leak',
        'clean_a_helper_that_freezes' => 'a helper\'s pin carries back',
        'clean_an_assertion_helper_called_frozen' => 'a helper is judged in its caller\'s state',
        'found_in_a_helper_of_a_helper' => 'helpers are followed through helpers',
        'clean_pinned_by_set_up' => 'setUp() is read',
        'found_let_go_after_set_up' => 'the walk starts from the set-up state',
        'clean_pinned_by_a_parent' => 'parent::setUp() is followed',
        'clean_inherited_and_pinned' => 'inherited tests are read, named for the class running them',
        'clean_pinned_by_a_trait' => 'setUp<Trait>() is read',
        'test_clean_pinned_by_a_before_method' => '#[Before] is read, and test* names are tests',
        'ignored_a_doc_tagged_method' => 'a @test doc tag does not make a test (PHPUnit 12 reads attributes only)',
        'clean_pinned_through_self' => 'self:: resolves from the class that wrote it',
        'found_the_native_clock_even_frozen' => 'PHP\'s own clock is a finding pinned',
        'found_in_a_trait_test' => 'a test declared in a trait is read',
        'clean_this_resolves_from_the_running_class' => '$this-> resolves from the class running the test',
        'clean_static_resolves_from_the_running_class' => 'static:: resolves from the class running the test',
        'clean_a_trait_method_before_the_parents' => 'a method resolves from traits before the parent',
        'found_the_classs_own_method_before_its_traits' => 'a method resolves from the class before its traits',
        'clean_pinned_by_a_parents_trait' => 'set-up is gathered from the parents\' traits',
        'clean_pinned_by_a_trait_a_trait_uses' => 'set-up is gathered from traits a trait uses',
        'clean_pinned_by_a_parents_before' => '#[Before] is gathered from parents',
        'found_a_static_test' => 'a public static method is a test (PHPUnit 12 does not check static)',
        'ignored_a_protected_method' => 'a non-public method is not a test',
        'found_in_another_closure' => 'any closure is walked where it is written',
        'found_after_an_arrow_function_that_freezes' => 'an arrow function\'s pin does not leak',
        'clean_a_pin_in_a_receiver' => 'a call\'s receiver is walked',
        'clean_a_pin_in_an_argument' => 'a call\'s arguments are walked',
        'clean_an_assertion_whose_arguments_pin' => 'an assertion is judged after its arguments are walked',
        'found_native_inside_a_test_now_scope' => 'a test-now scope\'s callback is its second argument',
        'clean_freeze_given_a_null_callback' => 'a pin given a null callback pins',
        'clean_a_nullsafe_pin' => 'a nullsafe call on $this pins',
        'found_a_nullsafe_predicate_method' => 'a nullsafe predicate method is an equality',
        'clean_a_diff_between_two_stored_dates' => 'an implicit method reads the clock only given nothing',
        'clean_age_on_a_frozen_clock' => 'freezing reaches age',
        'clean_a_relative_word_that_is_not_the_first_argument' => 'only the first argument is read as a literal',
        'clean_a_named_argument_of_a_non_equality_assertion' => 'a named argument of an assertion that compares nothing is not compared',
        'clean_a_predicate_message' => 'a predicate\'s second argument is not compared',
        'clean_make_of_a_blank_literal' => 'Carbon::make(\'\') is null, not now',
        'found_a_constructor_of_a_blank_literal' => 'a constructor given \'\' is now',
        'found_a_function_style_assertion' => 'a function-style assertion is an assertion',
        'found_a_clock_function_in_capitals' => 'function names are read case-insensitively',
        'clean_an_unpacked_argument' => 'an unpacked argument is not read',
        'clean_a_class_declared_inside_a_test' => 'a class declared inside a test is not walked',
        'found_after_a_pin_given_a_callable' => 'a pin given a non-closure callable leaves the clock free',
        'found_an_assertion_inside_a_pin_argument' => 'a pin\'s other arguments are walked',
        'found_an_assertion_inside_a_travel_argument' => 'travel()\'s arguments are walked',
        'found_an_assertion_inside_a_setter_argument' => 'a test-now setter\'s arguments are walked',
        'clean_a_pin_in_a_nullsafe_receiver' => 'a nullsafe call\'s receiver is walked',
        'clean_before_from_a_trait' => 'a trait\'s #[Before] method runs',
        'found_let_go_after_the_traits_set_up' => 'the traits\' set-up runs inside parent::setUp(), before the rest of setUp()',
        'found_pinned_before_a_trait_lets_go' => 'what setUp() does before parent::setUp() comes before the traits\' set-up',
        'clean_pinned_after_a_trait_lets_go' => 'what setUp() does after parent::setUp() comes after the traits\' set-up',
        'found_traits_not_set_up_without_parent_set_up' => 'a setUp() that never calls parent::setUp() never runs the traits\' set-up',
        'found_set_up_runs_after_before' => '#[Before] methods run before setUp()',
        'clean_a_childs_before_runs_after_its_parents' => 'a parent\'s #[Before] runs before the child\'s',
        'found_a_doc_tagged_before_does_not_pin' => 'a @before doc tag is not read',
        'clean_a_nullsafe_wormhole' => 'a Wormhole reached through a nullsafe call pins',
        'clean_a_wormhole_on_a_nullsafe_travel' => 'a Wormhole on a nullsafe travel() pins',
        'clean_a_childs_trait_set_up_runs_after_its_parents' => 'traits\' set-up runs in class_uses_recursive() order, topmost parent first',
        'clean_a_static_before' => 'a static #[Before] method runs',
        'clean_a_negative_priority_before_runs_after_set_up' => 'hooks are ordered by priority: a negative #[Before] runs after setUp()',
        'found_a_higher_priority_before_runs_first' => 'a higher-priority #[Before] runs before a lower one (a positional priority is read)',
        'found_in_a_nested_trait_test' => 'a test declared in a trait a trait uses is read',
        'found_in_a_parents_trait_test' => 'a test declared in a trait a parent uses is read',
        'clean_trait_set_up_runs_again_at_a_second_parent_set_up' => 'the traits\' set-up runs each time parent::setUp() leaves the set',
        'clean_a_nested_traits_set_up_attribute_runs_with_its_user' => 'a trait\'s #[SetUp] methods include those of the traits it uses',
        'found_traits_not_set_up_when_the_parents_set_up_stops' => 'an in-set parent::setUp() is followed, not taken as Laravel\'s',
        'clean_a_before_overridden_in_another_case_runs_once' => '#[Before] methods are de-duplicated case-insensitively',
        'found_a_before_overridden_without_the_attribute' => 'a #[Before] method is resolved on the class running the test',
        'found_a_trait_set_up_the_class_overrides' => 'setUp<Trait>() is resolved on the class',
        'clean_before_on_set_up_is_ignored' => '#[Before] on setUp() is ignored',
        'clean_the_traits_set_up_through_an_overridden_runner' => 'an overridden setUpTraits() reaches the traits through parent::setUpTraits()',
    ];

    private static ?UnpinnedClockAssertions $suite = null;

    #[Test]
    public function no_test_compares_a_clock_read_with_an_unpinned_clock(): void
    {
        $offences = [];
        foreach (self::suite()->findings() as $finding) {
            if (! isset(self::EXCUSES[$finding['test']])) {
                $offences[] = sprintf(
                    '%s — %s at %s:%d%s',
                    $finding['test'],
                    $finding['assertion'],
                    $finding['file'],
                    $finding['line'],
                    $finding['native'] ? ' (PHP\'s own clock, which freezing does not reach)' : '',
                );
            }
        }

        $this->assertSame(
            [],
            $offences,
            "These assertions compare a value with a clock read made at the assertion, on a clock nothing pinned:\n  "
            .implode("\n  ", $offences)
            ."\n\nThe code stored its time earlier; the assertion reads the clock again, and a run that crosses a "
            .'second (or midnight) between the two is red. Pin the clock before the code runs '
            .'($this->freezeSecond()), or, where the code stores a time it was given, assert that input. Reading an '
            .'unpinned clock once into a variable is not enough: the code reads it later. The claim stays the same.',
        );
    }

    #[Test]
    public function every_excused_test_still_has_the_shape(): void
    {
        $this->assertSame(
            [],
            self::staleExcuses(self::suite()->findings(), self::EXCUSES),
            'These excuses name a test that no longer compares a clock read on an unpinned clock; remove them.',
        );
    }

    #[Test]
    public function a_stale_excuse_is_reported(): void
    {
        $findings = [['test' => 'A::found', 'file' => 'a.php', 'line' => 1, 'assertion' => 'assertSame', 'native' => false]];

        $this->assertSame([], self::staleExcuses($findings, ['A::found' => 'kept']));
        $this->assertSame(['A::gone'], self::staleExcuses($findings, ['A::found' => 'kept', 'A::gone' => 'fixed since']));
    }

    #[Test]
    public function the_controls_are_classified_exactly(): void
    {
        $source = (string) file_get_contents(self::root().'/'.self::FIXTURE);
        $methods = self::controlNames();
        $expected = array_values(array_filter($methods, static fn (string $m): bool => str_contains($m, 'found_')));
        $clean = array_values(array_filter($methods, static fn (string $m): bool => str_contains($m, 'clean_')));

        $scanner = new UnpinnedClockAssertions([self::FIXTURE => $source]);
        $found = array_values(array_unique(array_map(
            static fn (array $f): string => substr($f['test'], (int) strrpos($f['test'], '::') + 2),
            $scanner->findings(),
        )));
        sort($expected);
        sort($found);

        $this->assertGreaterThan(30, count($expected), 'The positive controls are missing.');
        $this->assertGreaterThan(15, count($clean), 'The negative controls are missing.');
        $this->assertSame(count($methods), $scanner->testsRead(), 'A control was not read as a test.');
        $this->assertSame(
            $expected,
            $found,
            "Missed (positive controls not reported):\n  ".implode("\n  ", array_diff($expected, $found))
            ."\nWrongly reported (negative controls):\n  ".implode("\n  ", array_diff($found, $expected)),
        );
    }

    #[Test]
    public function every_element_the_scanner_recognises_has_its_own_control(): void
    {
        $controls = self::controlNames(true);
        $fixture = (string) file_get_contents(self::root().'/'.self::FIXTURE);
        $required = [];
        $unnamed = [];
        $scanner = new ReflectionClass(UnpinnedClockAssertions::class);

        foreach (UnpinnedClockAssertions::VOCABULARY as $constant => [$kind, $prefix]) {
            $list = $scanner->getConstant($constant);
            $this->assertIsArray($list, "{$constant} is not a list the scanner has.");
            foreach (array_is_list($list) ? $list : array_keys($list) as $element) {
                $slug = (string) preg_replace('/\W/', '', substr((string) strrchr('\\'.$element, '\\'), 1));
                if ($kind === 'named') {
                    // The control is a #[Test] method named the element itself.
                    if (! preg_match('/#\[Test\]\s+public (?:static )?function '.preg_quote($slug, '/').'\(/i', $fixture)) {
                        $unnamed[] = "{$constant}: {$slug}";
                    }

                    continue;
                }
                $required[] = sprintf('%s_%s__%s', $kind, $prefix, $slug);
            }
        }
        $this->assertSame([], $unnamed, 'These hook names have no #[Test] method of that name in the fixture.');
        foreach (UnpinnedClockAssertions::EQUALITY as $assertion => $positions) {
            foreach ($positions as $position) {
                $required[] = "found_equality__{$assertion}__{$position}";
            }
        }

        $unlisted = [];
        foreach ($scanner->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && is_array($constant->getValue())
                && ! in_array($constant->getName(), ['VOCABULARY', 'EQUALITY'], true)
                && ! isset(UnpinnedClockAssertions::VOCABULARY[$constant->getName()])) {
                $unlisted[] = $constant->getName();
            }
        }

        $this->assertSame([], $unlisted, 'These lists decide what the scanner reads but have no controls: add them to VOCABULARY.');
        // A control satisfies a requirement by its name, or by its name after a
        // prefix that makes it a test (`test_found_test_prefix__test`).
        $satisfies = static fn (string $control, string $wanted): bool => $control === $wanted || str_ends_with($control, '_'.$wanted);
        $this->assertSame(
            [],
            array_values(array_filter($required, static fn (string $r): bool => array_filter($controls, static fn (string $c): bool => $satisfies($c, $r)) === [])),
            'These elements have no control of their own in the fixture.',
        );
        $this->assertSame(
            [],
            array_values(array_filter($controls, static fn (string $c): bool => str_contains($c, '__')
                && array_filter($required, static fn (string $r): bool => $satisfies($c, $r)) === [])),
            'These controls name an element no list holds any more.',
        );
        $this->assertSame(
            [],
            array_values(array_filter(array_keys(self::STRUCTURAL), static fn (string $c): bool => ! str_contains($fixture, "function {$c}("))),
            'These structural controls are missing.',
        );
        $this->assertSame(array_values(array_unique($controls)), $controls, 'Two controls share a name, so one of them is not told apart.');
    }

    /**
     * A finding inside a helper names the file that holds the helper, not the
     * test's: the one place a reader has to go to fix it.
     */
    #[Test]
    public function a_finding_in_a_helper_names_the_file_that_holds_it(): void
    {
        $scanner = new UnpinnedClockAssertions([
            'tests/A.php' => '<?php namespace T; final class ATest extends \\Tests\\TestCase { use H; #[\\PHPUnit\\Framework\\Attributes\\Test] public function t(): void { $this->check(); } }',
            'tests/H.php' => '<?php namespace T; trait H { private function check(): void { $this->assertEquals(now(), $this->at); } }',
        ]);

        $this->assertSame(['tests/H.php'], array_column($scanner->findings(), 'file'));
    }

    #[Test]
    public function the_walk_reads_the_suite(): void
    {
        $this->assertGreaterThan(4000, self::suite()->testsRead(), 'The walk read almost no tests.');
        $this->assertGreaterThan(200, self::suite()->pinsSeen(), 'The walk saw almost no pins.');
    }

    /**
     * @param  list<array{test: string, file: string, line: int, assertion: string, native: bool}>  $findings
     * @param  array<string, string>  $excuses
     * @return list<string>
     */
    private static function staleExcuses(array $findings, array $excuses): array
    {
        $tests = array_column($findings, 'test');

        return array_values(array_filter(
            array_keys($excuses),
            static fn (string $test): bool => ! in_array($test, $tests, true),
        ));
    }

    /**
     * @return list<string>
     */
    private static function controlNames(bool $withIgnored = false): array
    {
        $kinds = $withIgnored ? 'found|clean|ignored' : 'found|clean';
        preg_match_all('/function (\w*(?:'.$kinds.')_\w+)\(/', (string) file_get_contents(self::root().'/'.self::FIXTURE), $matches);

        return $matches[1];
    }

    private static function suite(): UnpinnedClockAssertions
    {
        if (self::$suite === null) {
            $sources = [];
            $root = self::root();
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
            if ($sources === []) {
                throw new RuntimeException('No test sources were found under '.$root.'/tests.');
            }
            ksort($sources);
            self::$suite = new UnpinnedClockAssertions($sources);
        }

        return self::$suite;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
