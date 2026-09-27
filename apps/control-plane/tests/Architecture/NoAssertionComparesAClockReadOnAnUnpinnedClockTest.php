<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
 * It does **not** follow a clock read through a variable. Measured at
 * `02d7364` with variables followed, the walk reported five more tests
 * (`ApiTokenIssuanceTest`, `RowsAlreadyInFlightKeepTheirOwnClockTest`,
 * `ServerDatesAreTheCustomersTest` twice, `HostingUsageEndpointTest`), and in
 * every one the variable is the test's own read handed to the code as input
 * (an expiry posted, a column written by the test) and compared with what
 * the code kept of it: an assertion about the input, not about the clock. A
 * bound comparison with a tolerance (`assertLessThanOrEqual`,
 * `assertEqualsWithDelta`, `->lessThan(now())`) is not the shape either: it
 * does not change its answer when a second or a day turns over.
 *
 * ===========================================================================
 * WHAT HOLDS THE GATE ITSELF
 * ===========================================================================
 *
 *  - **Controls.** `Fixtures/clock-reads-at-assertions.php.txt` carries the
 *    `031f6c9` and `e0aa0ab` shapes verbatim and one test per form the
 *    scanner claims to read (`found_*`, each must be reported) beside one per
 *    form it claims to excuse (`clean_*`, none may be). Both lists are
 *    compared exactly, so a narrowed reader and a widened one both go red.
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
        preg_match_all('/function (\w*(?:found|clean)_\w+)\(/', $source, $matches);
        $methods = $matches[1];
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
