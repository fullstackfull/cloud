<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * F-32: one place decides which hosting package a plan is sold under.
 *
 * Two modules — checkout's placement check and the plan change — each asked
 * `HostingPackage::query()->where('plan_id', …)->first()`, which hands back
 * whichever row a scan meets first: withdrawn or not, and a different one
 * after any UPDATE. Both now ask `HostingPackageForPlan`, which takes the
 * package on sale when there is exactly one and refuses otherwise. This gate
 * fails on any other read that narrows hosting packages by a plan id and takes
 * one row out of the result, because that read is a second resolver and the
 * next one will not have the `is_active` filter either.
 *
 * ---------------------------------------------------------------------------
 * What it reads
 * ---------------------------------------------------------------------------
 *
 * Every PHP file under `src/`, `app/` and `database/` — the three places code
 * that runs against the catalogue lives: modules, console wiring, and the
 * seeders and factories that build estates people then buy from. Tests are
 * not read: several of them pick a package by plan on purpose, with `sole()`,
 * to assert there is exactly one. The walk asserts it read at least one file
 * under each directory, because narrowing the list back to `src/` would leave
 * every other assertion here green — nothing under `app/` or `database/`
 * offends today.
 *
 * Comments are stripped before anything is matched. The resolver's own
 * docblock quotes the defective query, and a gate that matched it there would
 * have to exempt the resolver by name — a test made to pass by a comment.
 *
 * ---------------------------------------------------------------------------
 * What it matches
 * ---------------------------------------------------------------------------
 *
 * One expression at a time, over PHP's own tokens: a chain that starts at the
 * model (`HostingPackage::…`, fully qualified, through a `use … as` alias, or
 * as `static::`/`self::` inside the model) or at
 * `DB::table('hosting_packages')`, followed by its `->method(…)` calls.
 * It offends when both halves are in the same chain:
 *
 *   - narrowed by plan: a `'plan_id'` (or `'hosting_packages.plan_id'`)
 *     literal anywhere in the chain's arguments, closures included, or a
 *     `wherePlanId(…)`; for `firstOrCreate`, `firstOrNew` and
 *     `updateOrCreate` only the first argument counts, since that is the one
 *     the row is found by;
 *   - one row taken: any `first…` method, `sole`, `value`, `updateOrCreate`,
 *     `limit(1)` / `take(1)`, or `[0]` on the result.
 *
 * The spellings it catches, the ones it must leave alone, and the ones it
 * cannot see are three tables below, each asserted rather than described.
 * The last table is the honest limit of a textual gate: a builder carried in a
 * variable between statements, a relation, a column name held in a variable,
 * raw SQL, a query started from a model instance, and a local scope that hides
 * the column. Each of those would get past it.
 */
final class OnlyOneResolverChoosesAHostingPackageForAPlanTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** @var list<string> */
    private const array ROOTS = ['src', 'app', 'database'];

    /**
     * Each of these is a second resolver and must be caught.
     *
     * @var array<string, string>
     */
    private const array CAUGHT = [
        'the original defect' => "HostingPackage::query()->where('plan_id', \$id)->first();",
        'a static builder' => "HostingPackage::where('plan_id', \$id)->first();",
        'firstWhere on the model' => "HostingPackage::firstWhere('plan_id', \$id);",
        'firstWhere on a builder' => "HostingPackage::query()->firstWhere('plan_id', \$id);",
        'an explicit operator' => "HostingPackage::query()->where('plan_id', '=', \$id)->first();",
        'an array of conditions' => "HostingPackage::query()->where(['plan_id' => \$id])->first();",
        'narrowed second' => "HostingPackage::query()->where('is_active', true)->where('plan_id', \$id)->first();",
        'a tiebreak nobody settled' => "HostingPackage::query()->where('plan_id', \$id)->latest()->first();",
        'firstOrFail' => "HostingPackage::query()->where('plan_id', \$id)->firstOrFail();",
        'sole' => "HostingPackage::query()->where('plan_id', \$id)->sole();",
        'one column of one row' => "HostingPackage::query()->where('plan_id', \$id)->value('id');",
        'first of a collection' => "HostingPackage::query()->where('plan_id', \$id)->get()->first();",
        'first of a plucked list' => "HostingPackage::query()->where('plan_id', \$id)->pluck('id')->first();",
        'limit one' => "HostingPackage::query()->where('plan_id', \$id)->limit(1)->get();",
        'take one' => "HostingPackage::query()->where('plan_id', \$id)->take(1)->get();",
        'index zero' => "HostingPackage::query()->where('plan_id', \$id)->get()[0];",
        'a dynamic where' => 'HostingPackage::query()->wherePlanId($id)->first();',
        'a qualified column' => "HostingPackage::query()->where('hosting_packages.plan_id', \$id)->first();",
        'fully qualified' => "\\Lynomia\\Modules\\SharedHosting\\Infrastructure\\Models\\HostingPackage::query()->where('plan_id', \$id)->first();",
        'an alias' => "use Lynomia\\Modules\\SharedHosting\\Infrastructure\\Models\\HostingPackage as Package;\nPackage::query()->where('plan_id', \$id)->first();",
        'narrowed inside a closure' => "HostingPackage::query()->where(fn (\$q) => \$q->where('plan_id', \$id))->first();",
        'across lines, with a comment in the chain' => "HostingPackage::query()\n    ->where('plan_id', \$id)\n    // the one we want\n    ->first();",
        'the query builder' => "DB::table('hosting_packages')->where('plan_id', \$id)->first();",
        'inside the model, through static' => "class HostingPackage extends Model\n{\n    public static function forPlan(string \$id): ?self\n    {\n        return static::query()->where('plan_id', \$id)->first();\n    }\n}",
        'firstOrCreate keyed by plan' => "HostingPackage::firstOrCreate(['plan_id' => \$id], ['slug' => 'x']);",
        'updateOrCreate keyed by plan' => "HostingPackage::updateOrCreate(['plan_id' => \$id], ['panel_package_name' => 'x']);",
    ];

    /**
     * Each of these is a read the gate must leave alone.
     *
     * @var array<string, string>
     */
    private const array LEFT_ALONE = [
        'asking whether any exist' => "HostingPackage::query()->where('plan_id', \$id)->exists();",
        'counting them' => "HostingPackage::query()->where('plan_id', \$id)->count();",
        "the resolver's own read" => "HostingPackage::query()->where('plan_id', \$id)->where('is_active', true)->limit(2)->get();",
        'by slug' => "HostingPackage::query()->where('slug', \$slug)->first();",
        'any package at all' => 'HostingPackage::query()->first();',
        'by id' => 'HostingPackage::query()->findOrFail($id);',
        "another table's plan_id" => "PlanPrice::query()->where('plan_id', \$id)->first();",
        'keyed by slug, plan among the values' => "HostingPackage::updateOrCreate(['slug' => \$slug], ['plan_id' => \$id]);",
        'the operator listing' => "HostingPackage::query()->when(\$plan, fn (\$q) => \$q->where('plan_id', \$plan))->orderBy('slug')->get();",
        'the defect quoted in a comment' => "/* HostingPackage::query()->where('plan_id', \$id)->first() */\n\$x = 1;",
        'the defect quoted in a string' => "\$message = \"HostingPackage::query()->where('plan_id', \$id)->first()\";",
        'a neighbouring statement' => "if (HostingPackage::query()->where('plan_id', \$id)->exists()) { \$plan = Plan::query()->first(); }",
    ];

    /**
     * Each of these IS a second resolver, and the gate cannot see it.
     *
     * Asserted so that the list stays true: a change that starts catching one
     * moves it to CAUGHT, and nobody is told the gate covers more than it does.
     *
     * @var array<string, string>
     */
    private const array BLIND_TO = [
        'a builder carried in a variable' => "\$query = HostingPackage::query()->where('plan_id', \$id);\n\$package = \$query->first();",
        'a relation' => '$package = $plan->hasMany(HostingPackage::class)->first();',
        'a column name held in a variable' => '$package = HostingPackage::query()->where($column, $id)->first();',
        'raw SQL' => "\$row = DB::selectOne('select * from hosting_packages where plan_id = ? limit 1', [\$id]);",
        'a query started from an instance' => "\$package = (new HostingPackage)->newQuery()->where('plan_id', \$id)->first();",
        'a scope that hides the column' => '$package = HostingPackage::query()->forPlan($id)->first();',
    ];

    #[Test]
    public function nothing_but_the_resolver_takes_one_hosting_package_for_a_plan(): void
    {
        $walk = self::walk(self::ROOT);

        $this->assertSame(
            [],
            $walk['offenders'],
            "These choose a hosting package for a plan without HostingPackageForPlan — by row order, and\n"
            ."with nothing to tell a withdrawn package from the one on sale:\n  ".implode("\n  ", $walk['offenders']),
        );
    }

    #[Test]
    public function the_walk_reads_every_directory_it_names(): void
    {
        $walk = self::walk(self::ROOT);

        /*
         * Spelled out rather than read back from ROOTS: this is the claim the
         * class docblock makes, and a loop over the constant would agree with
         * whatever the constant had been narrowed to.
         */
        foreach (['src', 'app', 'database'] as $root) {
            $this->assertGreaterThan(
                0,
                $walk['read'][$root] ?? 0,
                sprintf('The walk read no PHP file under %s/, so it claims a directory it does not measure.', $root),
            );
        }
    }

    #[Test]
    public function the_walk_finds_an_offender_planted_in_any_directory_it_names(): void
    {
        $base = sys_get_temp_dir().'/f32-walk-'.bin2hex(random_bytes(6));

        try {
            foreach (self::ROOTS as $root) {
                self::plant($base.'/'.$root.'/Clean.php', "HostingPackage::query()->where('plan_id', \$id)->exists();");
            }

            $this->assertSame([], self::walk($base)['offenders'], 'A clean tree reported an offender.');

            self::plant($base.'/app/Probe.php', "HostingPackage::where('plan_id', \$id)->first();");
            self::plant($base.'/database/seeders/Probe.php', "HostingPackage::firstWhere('plan_id', \$id);");

            $offenders = self::walk($base)['offenders'];

            $this->assertCount(2, $offenders, implode("\n", $offenders));
            $this->assertStringStartsWith('app/Probe.php', $offenders[0]);
            $this->assertStringStartsWith('database/seeders/Probe.php', $offenders[1]);
        } finally {
            self::remove($base);
        }
    }

    #[Test]
    public function the_matcher_catches_every_spelling_it_claims(): void
    {
        $missed = array_keys(array_filter(self::CAUGHT, static fn (string $code): bool => self::offendingChains($code) === []));

        $this->assertSame([], $missed, 'Spellings of the defect the gate says it catches and does not: '.implode(', ', $missed));
    }

    #[Test]
    public function the_matcher_leaves_alone_what_is_not_a_choice(): void
    {
        $flagged = array_keys(array_filter(self::LEFT_ALONE, static fn (string $code): bool => self::offendingChains($code) !== []));

        $this->assertSame([], $flagged, 'Reads that choose nothing were reported: '.implode(', ', $flagged));
    }

    #[Test]
    public function the_matcher_is_blind_to_exactly_what_it_says_it_is_blind_to(): void
    {
        $seen = array_keys(array_filter(self::BLIND_TO, static fn (string $code): bool => self::offendingChains($code) !== []));

        $this->assertSame(
            [],
            $seen,
            'The gate now catches these; move them to CAUGHT and correct the class docblock: '.implode(', ', $seen),
        );
    }

    // -----------------------------------------------------------------

    /**
     * @return array{offenders: list<string>, read: array<string, int>}
     */
    private static function walk(string $base): array
    {
        $offenders = [];
        $read = [];

        foreach (self::ROOTS as $root) {
            $directory = $base.'/'.$root;

            if (! is_dir($directory)) {
                continue;
            }

            $files = [];

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }

            sort($files);

            foreach ($files as $path) {
                $read[$root] = ($read[$root] ?? 0) + 1;

                foreach (self::offendingChains((string) file_get_contents($path)) as $chain) {
                    $offenders[] = substr($path, strlen($base) + 1).': '.$chain;
                }
            }
        }

        return ['offenders' => $offenders, 'read' => $read];
    }

    /**
     * @return list<string>
     */
    private static function offendingChains(string $source): array
    {
        if (! str_starts_with(ltrim($source), '<?php')) {
            $source = "<?php\n".$source;
        }

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true),
        ));

        $names = self::namesForTheModel($tokens);
        $found = [];

        foreach (array_keys($tokens) as $at) {
            $chain = self::chainAt($tokens, $at, $names);

            if ($chain !== null && self::narrowsByPlan($chain['calls']) && self::takesOneRow($chain)) {
                $found[] = $chain['text'];
            }
        }

        return $found;
    }

    /**
     * Every name this file uses for the model: its own, and any alias.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<string>
     */
    private static function namesForTheModel(array $tokens): array
    {
        $names = ['HostingPackage'];

        foreach ($tokens as $i => $token) {
            // Inside the model itself, `static::` and `self::` are the model.
            if (is_array($token) && $token[0] === T_CLASS
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][1] === 'HostingPackage') {
                $names[] = 'static';
                $names[] = 'self';
            }

            if (is_array($token) && $token[0] === T_AS
                && self::lastSegment($tokens[$i - 1] ?? null) === 'HostingPackage'
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING) {
                $names[] = $tokens[$i + 1][1];
            }
        }

        return $names;
    }

    /**
     * The chain that starts at this token, when one does.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  list<string>  $names
     * @return array{calls: list<array{name: string, args: list<array{0: int, 1: string, 2: int}|string>}>, indexZero: bool, text: string}|null
     */
    private static function chainAt(array $tokens, int $at, array $names): ?array
    {
        $head = self::lastSegment($tokens[$at]);
        $separator = $tokens[$at + 1] ?? null;
        $method = $tokens[$at + 2] ?? null;

        if ($head === null || ! is_array($separator) || $separator[0] !== T_DOUBLE_COLON
            || ! is_array($method) || $method[0] !== T_STRING || ($tokens[$at + 3] ?? null) !== '(') {
            return null;
        }

        [$args, $next] = self::group($tokens, $at + 3);

        $fromTheModel = in_array($head, $names, true);
        $fromTheTable = $head === 'DB' && strtolower($method[1]) === 'table'
            && in_array('hosting_packages', self::literals($args), true);

        if (! $fromTheModel && ! $fromTheTable) {
            return null;
        }

        $calls = [['name' => $method[1], 'args' => $args]];

        while (is_array($tokens[$next] ?? null)
            && in_array($tokens[$next][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && is_array($tokens[$next + 1] ?? null) && $tokens[$next + 1][0] === T_STRING
            && ($tokens[$next + 2] ?? null) === '(') {
            $name = $tokens[$next + 1][1];
            [$args, $next] = self::group($tokens, $next + 2);
            $calls[] = ['name' => $name, 'args' => $args];
        }

        $indexZero = ($tokens[$next] ?? null) === '['
            && is_array($tokens[$next + 1] ?? null) && $tokens[$next + 1][1] === '0'
            && ($tokens[$next + 2] ?? null) === ']';

        $text = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            array_slice($tokens, $at, $next - $at),
        ));

        return ['calls' => $calls, 'indexZero' => $indexZero, 'text' => $text];
    }

    /**
     * @param  list<array{name: string, args: list<array{0: int, 1: string, 2: int}|string>}>  $calls
     */
    private static function narrowsByPlan(array $calls): bool
    {
        foreach ($calls as $call) {
            $name = strtolower($call['name']);

            if ($name === 'whereplanid' || $name === 'orwhereplanid') {
                return true;
            }

            $searched = in_array($name, ['firstorcreate', 'firstornew', 'updateorcreate'], true)
                ? self::firstArgument($call['args'])
                : $call['args'];

            foreach (self::literals($searched) as $literal) {
                if ($literal === 'plan_id' || str_ends_with($literal, '.plan_id')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{calls: list<array{name: string, args: list<array{0: int, 1: string, 2: int}|string>}>, indexZero: bool, text: string}  $chain
     */
    private static function takesOneRow(array $chain): bool
    {
        if ($chain['indexZero']) {
            return true;
        }

        foreach ($chain['calls'] as $call) {
            $name = strtolower($call['name']);

            if (str_starts_with($name, 'first') || in_array($name, ['sole', 'value', 'updateorcreate'], true)) {
                return true;
            }

            if (in_array($name, ['limit', 'take'], true)
                && count($call['args']) === 1 && is_array($call['args'][0]) && $call['args'][0][1] === '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * The tokens between a '(' and its matching ')', and the index after it.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: list<array{0: int, 1: string, 2: int}|string>, 1: int}
     */
    private static function group(array $tokens, int $open): array
    {
        $depth = 0;

        for ($i = $open; $i < count($tokens); $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    return [array_slice($tokens, $open + 1, $i - $open - 1), $i + 1];
                }
            }
        }

        return [array_slice($tokens, $open + 1), count($tokens)];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $args
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function firstArgument(array $args): array
    {
        $depth = 0;

        foreach ($args as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                return array_slice($args, 0, $i);
            }
        }

        return $args;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<string>
     */
    private static function literals(array $tokens): array
    {
        $literals = [];

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = substr($token[1], 1, -1);
            }
        }

        return $literals;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string|null  $token
     */
    private static function lastSegment(array|string|null $token): ?string
    {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $segments = explode('\\', $token[1]);

        return end($segments);
    }

    private static function plant(string $path, string $code): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, "<?php\n\n".$code."\n");
    }

    private static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
