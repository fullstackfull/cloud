<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * What retiring a dedicated server writes, what it does not, and the two
 * places that used to say otherwise.
 *
 * ===========================================================================
 * WHY THIS EXISTS (F-47)
 * ===========================================================================
 *
 * `DedicatedServerStatus::Retired` had six readers — `isTerminal()`, the
 * model's `isRetired()`, the retirement-date filters in
 * `DedicatedServer::scopeAllocatable()` and `CustomerDedicatedServers::of()`,
 * the inventory sweep's exclusion, and a race argued in `SyncDedicatedServer`
 * about a machine "Retired between the sweep and the worker" — and no
 * production writer; only a test factory produced it. F-12's
 * `RetireDedicatedServer` is now that writer, so the state half of the finding
 * is closed by a real operator act, and the general gate
 * ({@see EveryStateAMachineCanEnterHasAProducerTest}) holds it: that gate
 * carries no excuse for `Retired`, and none should be added back.
 *
 * Two things were left, and this file holds both.
 *
 *  - **The race sentence was false twice**, and only one of its two falsehoods
 *    went away with F-12. It explained a *missing row* by retirement; but
 *    retirement never removes a row — the enum, the state machine, the model
 *    and the factory all say so, because the row is what answers "where did
 *    this serial number go". The same sentence had been copied into
 *    `DedicatedInventorySweepTest` as "Retired and removed".
 *
 *  - **The date has no writer.** `RetireDedicatedServer` sets the status and
 *    never `retired_at`, so the column still has a schema, a cast and two
 *    `whereNull('retired_at')` filters and nothing in production that writes
 *    it. Neither filter is broken — each query's status filter alone excludes
 *    a retired machine — but both are permanently inert, reading in review
 *    exactly like a filter doing work. {@see DedicatedServerStatus} says so,
 *    and the census below is what keeps that sentence true.
 *
 * ===========================================================================
 * THE CENSUS WAS REWRITTEN, NOT PATCHED
 * ===========================================================================
 *
 * Before F-12 the census asserted that the only writer of the retired *state*
 * was the test factory. That sentence stopped being true the day a production
 * act performed retirement, and the census then had no subject. Adding
 * `RetireDedicatedServer` beside the factory would have kept it green by
 * turning a claim about the platform into a running total of whatever is
 * there. So its subject moved to the part of retirement that is still not
 * performed — the date — and the state went to the gate whose subject is
 * states. The same applies the next time: when a production act stamps
 * `retired_at`, rewrite the enum's paragraph and this census; do not add the
 * act to ONLY_WRITER.
 *
 * ===========================================================================
 * WHAT THE CENSUS COUNTS AS A WRITE OF THE COLUMN
 * ===========================================================================
 *
 * Every PHP file under the five PRODUCTION directories is tokenised
 * (`PhpToken`, not a regex). A reference is the string `'retired_at'` (bare or
 * table-qualified) or the property `->retired_at`. It is a write in
 * assignment position —
 *
 *  - an array key, `'retired_at' => …`, except inside a method named exactly
 *    `casts`, where the key declares how the column is read;
 *  - an element assignment, `$attributes['retired_at'] = …` or `??=` — the
 *    accumulate-then-`forceFill($attributes)` idiom this codebase uses;
 *  - a property assignment, `$server->retired_at = …` or `??=`;
 *
 * — or in argument position, as the first argument of `setAttribute()` or
 * `touch()`; or as a bare element of an array literal whose nearest enclosing
 * call is anything but one of the FILTERS (`array_combine(['retired_at'], …)`).
 * Everything else is a read: `whereNull('retired_at')`, the migration's
 * `timestampTz('retired_at')`, the cast, a property read.
 * {@see self::the_census_counts_the_shapes_its_docblock_names()} runs each
 * named shape through the classifier, so this list cannot drift from the code.
 *
 * It is an enumeration of the ways a writer has been introduced here, not a
 * proof that none can be. A column name held in a variable, spelled through
 * `compact()` or `constant()`, or written inside a raw SQL string puts no
 * write-shaped token in the source; and some spellings escape with the token
 * present — `data_set()`, `Arr::set()`, `offsetSet()`, `->{'retired_at'}`, a
 * method called by a variable name, `setAttribute()` with named arguments.
 * That list is where an attack stopped, not where the classifier's limit is.
 * What is measured is the occupancy, which is zero.
 *
 * It over-counts on purpose, in the direction that is loud: an array key in a
 * query (`where(['retired_at' => null])`) and a bare element given to a call
 * that only reads (`only(['retired_at'])`) both count as writes. Neither
 * occurs today; the census's failure message says how to tell one from a real
 * writer.
 */
final class RetirementKeepsTheRowAndStampsNoDateTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Everything that runs or is loaded into a database outside a test. The
     * whole of `resources/` rather than its data directory, because an
     * exclusion by path is how the next writer arrives through the directory
     * next door.
     *
     * @var list<string>
     */
    private const array PRODUCTION = ['src', 'app', 'database', 'routes', 'resources'];

    /**
     * The known positive. The factory's `retired()` state stamps the date, so
     * a scan that fails to land goes red naming nothing at all rather than
     * passing on an empty list.
     */
    private const string ONLY_WRITER = 'database/factories/DedicatedServerFactory.php';

    private const string COLUMN = 'retired_at';

    /**
     * Query constraints, by exact name: a bare element of an array they are
     * given names a column to filter on, not one to assign.
     *
     * @var list<string>
     */
    private const array FILTERS = [
        'where', 'orwhere', 'wherenot', 'orwherenot', 'wherein', 'orwherein',
        'wherenotin', 'orwherenotin', 'wherenull', 'orwherenull', 'wherenotnull',
    ];

    /**
     * Calls whose first argument names the column they assign. `touch('col')`
     * stamps a fresh timestamp on the named column, which is exactly what a
     * retirement date is.
     *
     * @var list<string>
     */
    private const array SETTERS = ['setattribute', 'touch'];

    /**
     * The two places that explain a missing row. Each must say, in a comment,
     * that retirement keeps the row — both of them, unconditionally, so that
     * deleting the explanation is red rather than vacuously green.
     *
     * @var list<string>
     */
    private const array MISSING_ROW = [
        'src/Modules/Dedicated/Application/Jobs/SyncDedicatedServer.php',
        'tests/Feature/Dedicated/DedicatedInventorySweepTest.php',
    ];

    private const string ROW_SURVIVES = 'retirement keeps the row';

    /**
     * What the enum's own docblock must go on saying, each phrase with the
     * reason it is load-bearing. A string search, so absolute: rewording one
     * is a deliberate act that fails here first.
     *
     * @var array<string, string>
     */
    private const array DECLARATION = [
        'retirement keeps the row' => 'a retired machine is a row with a status, never a missing row',
        'nothing in production writes `retired_at`' => 'the sentence the census below holds',
        'permanently inert' => 'the consequence of that sentence for the two whereNull clauses',
        '`dedicatedserver::scopeallocatable()`' => 'the first of the two clauses, named',
        '`customerdedicatedservers::of()`' => 'the second of the two clauses, named',
        'the status filter alone' => 'why neither query is broken, so nobody "fixes" one',
    ];

    #[Test]
    public function the_only_writer_of_the_retirement_date_is_the_test_factory(): void
    {
        foreach (self::PRODUCTION as $directory) {
            $this->assertDirectoryExists(self::ROOT.'/'.$directory, "The census reads {$directory}/ and it is not there.");
        }

        $sites = [];

        foreach ($this->files() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, self::COLUMN)) {
                continue;
            }

            $relative = substr($path, strlen(self::ROOT) + 1);

            foreach ($this->writesIn($source) as [$line, $shape]) {
                $sites[$relative][] = "{$relative}:{$line} ({$shape})";
            }
        }

        $writers = array_keys($sites);
        sort($writers);

        $this->assertSame([self::ONLY_WRITER], $writers, sprintf(
            "The writers of dedicated_servers.retired_at are not exactly the test factory:\n  %s\n\n".
            'An empty list means the scan did not land, not that nothing writes. Any other file means one '.
            'of two things. A real writer is the event this census exists for: the two whereNull(\'retired_at\') '.
            'clauses stop being inert, so rewrite the paragraph in DedicatedServerStatus that says they are (and '.
            'the comment beside each clause), and rewrite this census for what is then true — do not add the new '.
            'file to ONLY_WRITER. Or it is '.
            'a read in a shape this classifier counts as a write (an array key in a query, say): if the only '.
            'change near the site is how a read is spelled, respell the read, and a real writer survives that.',
            $sites === [] ? '(nothing at all)' : implode("\n  ", array_merge(...array_values($sites))),
        ));
    }

    #[Test]
    public function the_enum_says_what_the_census_holds(): void
    {
        $declaration = self::normalise((string) (new ReflectionClass(DedicatedServerStatus::class))->getDocComment());

        $missing = [];

        foreach (self::DECLARATION as $phrase => $why) {
            if (! str_contains($declaration, $phrase)) {
                $missing[] = "\"{$phrase}\" — {$why}";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "DedicatedServerStatus's docblock no longer says:\n  %s\n\n".
            'It is where a reader learns that retirement is a status with a surviving row, that nothing in '.
            'production stamps the date, and that the two whereNull(\'retired_at\') clauses are inert rather '.
            'than broken. If one of those stopped being true, the census in this file says which.',
            implode("\n  ", $missing),
        ));
    }

    #[Test]
    public function both_places_that_explain_a_missing_row_say_retirement_keeps_it(): void
    {
        /*
         * Positive and unconditional. A rule forbidding the word "retire"
         * would be satisfied by deleting the explanation and would forbid the
         * sentence that corrects it; a rule that applied only when the file
         * spoke of retirement would pass the moment the passage was deleted.
         *
         * Disclosed: the phrase is matched anywhere in the file's comments, not
         * bound to the missing-row branch. Keeping it elsewhere while writing
         * the false attribution back is a two-edit escape.
         */
        $silent = [];

        foreach (self::MISSING_ROW as $relative) {
            $this->assertFileExists(self::ROOT.'/'.$relative);

            $comments = '';

            foreach (PhpToken::tokenize((string) file_get_contents(self::ROOT.'/'.$relative)) as $token) {
                if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $comments .= ' '.$token->text;
                }
            }

            if (! str_contains(self::normalise($comments), self::ROW_SURVIVES)) {
                $silent[] = $relative;
            }
        }

        $this->assertSame([], $silent, sprintf(
            "These explain a missing dedicated-server row without saying that %s:\n  %s\n\n".
            'A retired machine keeps its row — the history is what the row survives for — so retirement is never '.
            'why a row is missing, and a machine retired after the sweep is found by its worker.',
            self::ROW_SURVIVES,
            implode("\n  ", $silent),
        ));
    }

    #[Test]
    public function the_census_counts_the_shapes_its_docblock_names(): void
    {
        $writes = [
            'array key' => '$server->forceFill([\'retired_at\' => now()])->save();',
            'table-qualified array key' => 'DB::table(\'dedicated_servers\')->update([\'dedicated_servers.retired_at\' => now()]);',
            'array key in a method that is not casts()' => 'class M { protected function attributes(): array { return [\'retired_at\' => now()]; } }',
            'element assignment' => '$attributes[\'retired_at\'] = now(); $server->forceFill($attributes)->save();',
            'element ??=' => '$attributes[\'retired_at\'] ??= now();',
            'property assignment' => '$server->retired_at = now();',
            'property ??=' => '$server->retired_at ??= now();',
            'setAttribute()' => '$server->setAttribute(\'retired_at\', now());',
            'touch()' => '$server->touch(\'retired_at\');',
            'bare element of a call that is not a filter' => '$server->fill(array_combine([\'retired_at\'], [now()]));',
        ];

        $reads = [
            'whereNull()' => '$query->whereNull(\'retired_at\');',
            'table-qualified whereNull()' => '$query->whereNull(\'dedicated_servers.retired_at\');',
            'bare element of a filter' => '$query->whereNull([\'retired_at\']);',
            'bare element outside any call' => '$columns = [\'retired_at\'];',
            'the migration' => '$table->timestampTz(\'retired_at\')->nullable();',
            'the cast' => 'class M { protected function casts(): array { return [\'retired_at\' => \'immutable_datetime\']; } }',
            'property read' => '$when = $server->retired_at;',
            'comparison' => 'if ($server->retired_at === null) { return; }',
            'a comment' => '// $server->retired_at = now();',
        ];

        foreach ($writes as $shape => $code) {
            $this->assertCount(1, $this->writesIn('<?php '.$code), "The census does not count a write spelled as: {$shape}");
        }

        foreach ($reads as $shape => $code) {
            $this->assertSame([], $this->writesIn('<?php '.$code), "The census counts a read as a write: {$shape}");
        }
    }

    /**
     * @return list<array{int, string}> [line, shape] of each write of the column
     */
    private function writesIn(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
        ));

        /** @var list<array{kind: string, callee: ?string, arg: int}> $stack */
        $stack = [];
        $pendingCasts = false;
        $writes = [];

        foreach ($tokens as $i => $token) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(T_FUNCTION) && $next?->is(T_STRING)) {
                $pendingCasts = strtolower($next->text) === 'casts';
            }

            if ($token->text === '(') {
                $stack[] = $this->frameForParen($tokens, $i);

                continue;
            }

            if ($token->text === '[' || $token->is(T_ATTRIBUTE)) {
                $isIndex = $prev !== null && ($prev->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING]) || in_array($prev->text, [')', ']', '}'], true));
                $stack[] = ['kind' => $token->is(T_ATTRIBUTE) ? 'attribute' : ($isIndex ? 'index' : 'array'), 'callee' => null, 'arg' => 0];

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $stack[] = ['kind' => $token->text === '{' && $pendingCasts ? 'casts' : 'block', 'callee' => null, 'arg' => 0];
                $pendingCasts = false;

                continue;
            }

            if ($token->text === ';') {
                $pendingCasts = false;
            }

            if (in_array($token->text, [')', ']', '}'], true)) {
                array_pop($stack);

                continue;
            }

            if ($token->text === ',' && $stack !== []) {
                $stack[count($stack) - 1]['arg']++;

                continue;
            }

            $shape = $this->writeShape($tokens, $i, $stack);

            if ($shape !== null) {
                $writes[] = [$token->line, $shape];
            }
        }

        return $writes;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<array{kind: string, callee: ?string, arg: int}>  $stack
     */
    private function writeShape(array $tokens, int $i, array $stack): ?string
    {
        $token = $tokens[$i];
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        $assigns = static fn (?PhpToken $t): bool => $t !== null && ($t->text === '=' || $t->is(T_COALESCE_EQUAL));

        // The property: `->retired_at`, not a method of that name.
        if ($token->is(T_STRING) && $token->text === self::COLUMN && $prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return $assigns($next) ? 'property assignment' : null;
        }

        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $value = substr($token->text, 1, -1);

        if ($value !== self::COLUMN && ! str_ends_with($value, '.'.self::COLUMN)) {
            return null;
        }

        $top = $stack[count($stack) - 1] ?? null;

        if ($next?->is(T_DOUBLE_ARROW)) {
            $inCasts = array_filter($stack, static fn (array $frame): bool => $frame['kind'] === 'casts') !== [];

            return $inCasts ? null : 'array key';
        }

        if ($top === null) {
            return null;
        }

        $whole = static fn (array $before, array $after): bool => in_array($prev?->text, $before, true) && in_array($next?->text, $after, true);

        if ($top['kind'] === 'index' && $whole(['['], [']']) && $assigns($tokens[$i + 2] ?? null)) {
            return 'element assignment';
        }

        if ($top['kind'] === 'call' && $top['arg'] === 0 && in_array($top['callee'], self::SETTERS, true) && $whole(['('], [',', ')'])) {
            return 'argument of '.$top['callee'].'()';
        }

        // A bare element — not a value after `=>` — is a read only where the
        // nearest enclosing call is absent or is a query filter.
        if ($top['kind'] === 'array' && $whole(['[', ',', '('], [',', ']', ')'])) {
            for ($depth = count($stack) - 1; $depth >= 0; $depth--) {
                $frame = $stack[$depth];

                if ($frame['kind'] === 'array' || $frame['kind'] === 'group') {
                    continue;
                }

                if ($frame['kind'] === 'call' && ! in_array($frame['callee'], self::FILTERS, true)) {
                    return 'bare element of an array given to '.($frame['callee'] ?? 'a dynamic call').'()';
                }

                break;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{kind: string, callee: ?string, arg: int}
     */
    private function frameForParen(array $tokens, int $i): array
    {
        $prev = $tokens[$i - 1] ?? null;
        $before = $tokens[$i - 2] ?? null;

        if ($prev?->is(T_ARRAY)) {
            return ['kind' => 'array', 'callee' => null, 'arg' => 0];
        }

        if ($prev?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            // A declaration's parameter list is not a call.
            if ($before?->is(T_FUNCTION)) {
                return ['kind' => 'group', 'callee' => null, 'arg' => 0];
            }

            $name = ltrim($prev->text, '\\');

            return ['kind' => 'call', 'callee' => strtolower(substr($name, (int) strrpos('\\'.$name, '\\'))), 'arg' => 0];
        }

        if ($prev !== null && ($prev->is(T_VARIABLE) || in_array($prev->text, [')', ']', '}'], true))) {
            return ['kind' => 'call', 'callee' => null, 'arg' => 0];
        }

        return ['kind' => 'group', 'callee' => null, 'arg' => 0];
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach (self::PRODUCTION as $directory) {
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

    /**
     * Comment text as one lower-case line: the docblock's delimiters and
     * leading asterisks and the comment slashes dropped, whitespace collapsed,
     * so a phrase is found whichever way the paragraph happens to wrap.
     */
    private static function normalise(string $text): string
    {
        $text = (string) preg_replace('~^\s*\*/|/\*\*?|\*/|^\s*\*|//~m', ' ', $text);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}
