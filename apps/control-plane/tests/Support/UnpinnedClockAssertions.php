<?php

declare(strict_types=1);

namespace Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Tests\Architecture\NoAssertionComparesAClockReadOnAnUnpinnedClockTest;

/**
 * Assertions that compare a value with a clock read made at the assertion,
 * in a test whose clock is not pinned at that point.
 *
 * The classifier behind {@see NoAssertionComparesAClockReadOnAnUnpinnedClockTest}.
 * What it reads and what it does not see is stated here once, because the
 * gate's verdict is only as good as this list.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * The sources it is given (the gate gives it every `*.php` file under
 * `tests/`), parsed with nikic/php-parser and name-resolved. Every class and
 * trait in them is indexed, so `extends`, `use SomeTrait` and `$this->helper()`
 * resolve to code in the same set; anything outside it (the framework's
 * TestCase, vendor traits) is not read and is taken not to pin the clock.
 *
 * **Tests.** The public methods a non-abstract class runs — its own, and those
 * it inherits from its parents and traits in the set, as PHP resolves each
 * name — that carry `#[Test]`, a `@test` doc tag, or a name beginning `test`.
 * A test is named after the class that runs it.
 *
 * **The walk.** Each test is walked in source order, carrying one bit — is the
 * clock pinned here? It starts as whatever the class's set-up leaves it:
 * `setUp()` as resolved through the class, its traits and its parents (with
 * `parent::setUp()` followed), then every `setUp<TraitName>()` of the traits
 * the hierarchy uses (the methods Laravel's `setUpTraits()` calls), then every
 * `#[Before]`/`@before` method. Then, statement by statement:
 *
 *  - **pins**: `$this->freezeTime()`, `$this->freezeSecond()`,
 *    `$this->travelTo($t)`, `$this->travel($n)->days()` (any unit), and
 *    `Carbon|CarbonImmutable|Date::setTestNow($t)` with an argument that is
 *    not `null`;
 *  - **unpins**: `$this->travelBack()`, `Wormhole::back()`, and
 *    `…::setTestNow()` with no argument or `null`;
 *  - **the callback forms** (`freezeTime(fn)`, `travelTo($t, fn)`,
 *    `travel($n)->days(fn)`, `Carbon::withTestNow($t, fn)`): the closure's
 *    body is walked pinned, and after the call the clock is unpinned (the
 *    framework clears it), or, for `withTestNow`, back as it was;
 *  - **`$this->helper()`, `self::helper()`, `static::helper()`,
 *    `parent::helper()`** that resolve to a method in the set are walked
 *    inline, in the state of the call, and their effect on the bit carries
 *    back — so a helper that freezes pins, and an assertion inside a helper
 *    is judged in the state its caller calls it in;
 *  - **any other closure** is walked in the current state, and what it does to
 *    the bit does not leak out.
 *
 * **Assertions** are calls whose name begins `assert`, on any receiver
 * (`$this`, `self::`, a test response). One is a finding when the clock is not
 * pinned there and the compared value reads the clock:
 *
 *  - an **equality** assertion — {@see self::EQUALITY} lists each with the
 *    argument positions that are compared (a failure message is never read) —
 *    with a clock read flowing into a compared argument;
 *  - a **predicate** assertion (`assertTrue`, `assertFalse`, …) whose argument
 *    is an equality: `===`, `!==`, `==`, `!=` with a clock read flowing into a
 *    side, or a Carbon equality (`eq`, `equalTo`, `ne`, `notEqualTo`,
 *    `isSame*`, `isToday`, `isYesterday`, `isTomorrow`, `isCurrent*`,
 *    `isNext*`/`isLast*` of a unit, `isBirthday`) with a clock read on either
 *    side or, for the ones that compare with now when given nothing, no
 *    argument; through `!`, `&&` and `||`.
 *
 * A **clock read** is `now()`, `today()`, `Carbon|CarbonImmutable|Date::now()`,
 * `::today()`, `::yesterday()`, `::tomorrow()`, `::parse()` or `::make()`
 * with nothing or a relative word (`'now'`, `'today'`, …), `new Carbon()`
 * (or `CarbonImmutable`) with nothing or a relative word, and the Carbon
 * methods that read the clock when given nothing (`diffIn*()`,
 * `diffForHumans()`, `isToday()`, …, the `age` property). PHP's own clock —
 * `time()`, `microtime()`, `date($format)`, `gmdate($format)`,
 * `new DateTime()`, `new DateTimeImmutable()` — is also a clock read, and one
 * that freezing does not reach, so it is a finding pinned or not.
 *
 * A clock read **flows into** a value when it is the value, or the receiver of
 * a method chain or a property fetch that makes the value
 * (`now()->addDays(7)->toDateString()`, `now()->timestamp`), or an element of
 * an array literal, an operand of an operator, a part of an interpolated
 * string, a cast, a branch of a ternary or `match`, an argument of a plain
 * function (`json_encode`, `sprintf`), an argument of a static call on a
 * clock class (`Carbon::parse(now())`), or an argument of a Carbon method that
 * combines two dates (`diffIn*`, `isSame*`, `eq`, …).
 *
 * ===========================================================================
 * WHAT IT CANNOT SEE
 * ===========================================================================
 *
 *  - **A clock read held in a variable.** `$expected = now()->addDay();` and,
 *    later, `assertSame($expected, …)` is not read: the variable could equally
 *    hold the test's own read that it then stored, which is not the shape.
 *  - **Control flow.** The walk is in source order; a pin inside an `if`, a
 *    loop, or a `try` counts from its position on whether or not the branch
 *    runs, and a closure's body is judged where it is written, not where it
 *    is called.
 *  - **A clock read passed through a method call that is not a Carbon
 *    combine** (`$this->expectedFor(now())`, `$builder->where('at', now())`)
 *    does not flow; neither does one inside a closure argument.
 *  - **Code outside the set**: a pin or an assertion in a vendor trait or base
 *    class, a helper reached through a variable (`$helper->check()`) or a
 *    dynamic name, a data provider's values.
 *  - **Assertion helpers that do not begin `assert`**, such as
 *    `AssertableJson::where()` inside `assertJson(fn …)`.
 *  - **Bound comparisons.** `assertLessThanOrEqual`, `assertGreaterThan`,
 *    `assertEqualsWithDelta`, and predicate calls such as `lessThan`,
 *    `isBefore`, `isPast` are deliberately not findings: a bound with a
 *    tolerance does not change its answer when a run crosses a second or a
 *    day, which is what this shape is about.
 */
final class UnpinnedClockAssertions
{
    /**
     * Equality assertions, with the positions of the arguments they compare.
     *
     * @var array<string, list<int>>
     */
    public const array EQUALITY = [
        'assertSame' => [0, 1],
        'assertNotSame' => [0, 1],
        'assertEquals' => [0, 1],
        'assertNotEquals' => [0, 1],
        'assertEqualsCanonicalizing' => [0, 1],
        'assertEqualsIgnoringCase' => [0, 1],
        'assertStringContainsString' => [0, 1],
        'assertStringNotContainsString' => [0, 1],
        'assertStringStartsWith' => [0, 1],
        'assertStringEndsWith' => [0, 1],
        'assertContains' => [0, 1],
        'assertNotContains' => [0, 1],
        'assertContainsEquals' => [0, 1],
        'assertArrayHasKey' => [0],
        'assertMatchesRegularExpression' => [0, 1],
        'assertJsonStringEqualsJsonString' => [0, 1],
        'assertDatabaseHas' => [1],
        'assertDatabaseMissing' => [1],
        'assertJsonPath' => [1],
        'assertJson' => [0],
        'assertExactJson' => [0],
        'assertJsonFragment' => [0],
        'assertJsonMissing' => [0],
        'assertJsonMissingExact' => [0],
        'assertSee' => [0],
        'assertSeeText' => [0],
        'assertDontSee' => [0],
        'assertSessionHas' => [1],
    ];

    /** Assertions whose one argument is a condition. */
    private const array PREDICATE = ['assertTrue', 'assertFalse', 'assertNotTrue', 'assertNotFalse'];

    /** Classes whose static clock reads freezing reaches, by short name. */
    private const array CLOCK_CLASSES = ['Carbon', 'CarbonImmutable', 'Date', 'CarbonInterface'];

    private const array CLOCK_STATICS = ['now', 'today', 'yesterday', 'tomorrow'];

    private const array RELATIVE_WORDS = ['now', 'today', 'yesterday', 'tomorrow'];

    /** Laravel's Wormhole units: `travel($n)->unit()`. */
    private const array WORMHOLE_UNITS = [
        'microsecond', 'microseconds', 'millisecond', 'milliseconds', 'second', 'seconds',
        'minute', 'minutes', 'hour', 'hours', 'day', 'days', 'week', 'weeks',
        'month', 'months', 'year', 'years',
    ];

    /** Carbon methods that read the clock when given nothing to compare with. */
    private const string IMPLICIT = '/^(isToday|isYesterday|isTomorrow|isCurrent[A-Z]\w*|isNext(Week|Month|Year|Quarter|Decade|Century|Millennium)|isLast(Week|Month|Year|Quarter|Decade|Century|Millennium)|isSame\w+|isBirthday|diffIn\w+|floatDiffIn\w+|diffForHumans)$/';

    /** Carbon methods that compare, or combine, the receiver with an argument. */
    private const string COMBINE = '/^(eq|equalTo|ne|notEqualTo|isSame\w*|isBirthday|diffIn\w+|floatDiffIn\w+|diffForHumans|average|max|min|closest|farthest)$/';

    /** Carbon methods that are an equality, for a predicate assertion. */
    private const string CARBON_EQUALITY = '/^(eq|equalTo|ne|notEqualTo|isSame\w*|isToday|isYesterday|isTomorrow|isCurrent[A-Z]\w*|isNext(Week|Month|Year|Quarter|Decade|Century|Millennium)|isLast(Week|Month|Year|Quarter|Decade|Century|Millennium)|isBirthday)$/';

    /** @var array<string, array{node: ClassLike, file: string, parent: ?string, traits: list<string>}> */
    private array $classes = [];

    /** @var list<array{test: string, file: string, line: int, assertion: string, native: bool}> */
    private array $findings = [];

    private int $tests = 0;

    private int $pins = 0;

    /** @var list<string> */
    private array $stack = [];

    private string $test = '';

    private string $testClass = '';

    private string $file = '';

    /**
     * @param  array<string, string>  $sources  path => PHP source
     */
    public function __construct(array $sources)
    {
        $parser = (new ParserFactory)->createForHostVersion();
        $finder = new NodeFinder;

        foreach ($sources as $path => $source) {
            $ast = $parser->parse($source) ?? [];
            $traverser = new NodeTraverser(new NameResolver);
            $ast = $traverser->traverse($ast);

            foreach ($finder->findInstanceOf($ast, ClassLike::class) as $class) {
                if (! ($class instanceof Class_ || $class instanceof Trait_) || $class->namespacedName === null) {
                    continue;
                }

                $traits = [];
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof TraitUse) {
                        foreach ($stmt->traits as $trait) {
                            $traits[] = $trait->toString();
                        }
                    }
                }

                $this->classes[$class->namespacedName->toString()] = [
                    'node' => $class,
                    'file' => $path,
                    'parent' => $class instanceof Class_ && $class->extends !== null ? $class->extends->toString() : null,
                    'traits' => $traits,
                ];
            }
        }

        foreach ($this->classes as $fqn => $class) {
            $node = $class['node'];
            if (! $node instanceof Class_ || $node->isAbstract()) {
                continue;
            }

            $this->file = $class['file'];
            $this->testClass = $fqn;
            $initial = null;

            foreach ($this->testsOf($fqn) as $name => [$method, $declaredIn]) {
                $initial ??= $this->setUpState($fqn);
                $this->tests++;
                $this->test = $fqn.'::'.$name;
                $this->stack = [$this->test];
                $this->walk($method->stmts ?? [], $initial, $declaredIn);
            }
        }
    }

    /**
     * @return list<array{test: string, file: string, line: int, assertion: string, native: bool}>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function testsRead(): int
    {
        return $this->tests;
    }

    public function pinsSeen(): int
    {
        return $this->pins;
    }

    /**
     * The tests a concrete class runs: its own, and those it inherits from
     * its traits and its parents in the set, as PHP resolves each name.
     *
     * @return array<string, array{0: ClassMethod, 1: string}>
     */
    private function testsOf(string $class): array
    {
        $names = [];
        $queue = [$class];
        for ($c = $this->classes[$class]['parent']; $c !== null && isset($this->classes[$c]); $c = $this->classes[$c]['parent']) {
            $queue[] = $c;
        }
        $queue = [...$queue, ...$this->hierarchyTraits($class)];

        foreach ($queue as $c) {
            foreach (isset($this->classes[$c]) ? $this->classes[$c]['node']->getMethods() : [] as $method) {
                $names[$method->name->toString()] = true;
            }
        }

        $tests = [];
        foreach (array_keys($names) as $name) {
            $resolved = $this->findMethod($class, $name);
            if ($resolved !== null && self::isTest($resolved[0])) {
                $tests[$name] = $resolved;
            }
        }

        return $tests;
    }

    private static function isTest(ClassMethod $method): bool
    {
        if (! $method->isPublic() || $method->isStatic() || $method->stmts === null) {
            return false;
        }

        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return (bool) preg_match('/@test\b/', (string) $method->getDocComment()?->getText());
    }

    private static function isBefore(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Before') {
                    return true;
                }
            }
        }

        return (bool) preg_match('/@before\b/', (string) $method->getDocComment()?->getText());
    }

    /**
     * The pin state a test of this class starts in.
     */
    private function setUpState(string $class): bool
    {
        $this->test = $class.'::setUp';
        $this->stack = [$this->test];
        $pinned = false;

        $setUp = $this->findMethod($class, 'setUp');
        if ($setUp !== null) {
            $pinned = $this->walk($setUp[0]->stmts ?? [], $pinned, $setUp[1]);
        }

        foreach ($this->hierarchyTraits($class) as $trait) {
            $short = substr($trait, (int) strrpos('\\'.$trait, '\\'));
            $method = $this->findMethod($trait, 'setUp'.$short);
            if ($method !== null) {
                $pinned = $this->walk($method[0]->stmts ?? [], $pinned, $method[1]);
            }
        }

        for ($c = $class; $c !== null && isset($this->classes[$c]); $c = $this->classes[$c]['parent']) {
            foreach ($this->classes[$c]['node']->getMethods() as $method) {
                if (self::isBefore($method)) {
                    $pinned = $this->walk($method->stmts ?? [], $pinned, $c);
                }
            }
        }

        return $pinned;
    }

    /**
     * @return list<string>
     */
    private function hierarchyTraits(string $class): array
    {
        $found = [];
        $queue = [];
        for ($c = $class; $c !== null && isset($this->classes[$c]); $c = $this->classes[$c]['parent']) {
            $queue = [...$queue, ...$this->classes[$c]['traits']];
        }
        while ($queue !== []) {
            $trait = array_shift($queue);
            if (in_array($trait, $found, true)) {
                continue;
            }
            $found[] = $trait;
            $queue = [...$queue, ...($this->classes[$trait]['traits'] ?? [])];
        }

        return $found;
    }

    /**
     * A method as PHP would resolve it on the class: its own, its traits', then its parent's.
     *
     * @return array{0: ClassMethod, 1: string}|null the method and the class-like that declares it
     */
    private function findMethod(?string $class, string $name, int $depth = 0): ?array
    {
        if ($class === null || ! isset($this->classes[$class]) || $depth > 20) {
            return null;
        }

        $method = $this->classes[$class]['node']->getMethod($name);
        if ($method !== null) {
            return $method->stmts === null ? null : [$method, $class];
        }

        foreach ($this->classes[$class]['traits'] as $trait) {
            $found = $this->findMethod($trait, $name, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->findMethod($this->classes[$class]['parent'], $name, $depth + 1);
    }

    /**
     * Walks code in source order and returns whether the clock is pinned after it.
     *
     * @param  Node|array<mixed>|null  $node
     */
    private function walk(mixed $node, bool $pinned, string $owner): bool
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $pinned = $this->walk($child, $pinned, $owner);
            }

            return $pinned;
        }

        if (! $node instanceof Node) {
            return $pinned;
        }

        if ($node instanceof Function_ || $node instanceof ClassLike) {
            return $pinned;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            // Judged where it is written; what it does to the clock stays inside.
            $this->walk($node instanceof Closure ? $node->stmts : $node->expr, $pinned, $owner);

            return $pinned;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall || $node instanceof FuncCall) {
            return $this->call($node, $pinned, $owner);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $pinned = $this->walk($node->$name, $pinned, $owner);
        }

        return $pinned;
    }

    private function call(MethodCall|NullsafeMethodCall|StaticCall|FuncCall $call, bool $pinned, string $owner): bool
    {
        $name = $call->name instanceof Identifier ? $call->name->toString() : ($call->name instanceof Name ? $call->name->getLast() : null);
        $onThis = ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->var instanceof Variable && $call->var->name === 'this';
        $class = $call instanceof StaticCall && $call->class instanceof Name ? $call->class : null;
        $args = $call->isFirstClassCallable() ? [] : $call->getArgs();

        // freezeTime(), freezeSecond(), travelTo($t) — with or without a callback.
        if ($onThis && in_array($name, ['freezeTime', 'freezeSecond', 'travelTo'], true)) {
            return $this->pinCall($args, $name === 'travelTo' ? 1 : 0, $pinned, $owner);
        }

        // travel($n)->unit() — with or without a callback.
        if ($call instanceof MethodCall && in_array($name, self::WORMHOLE_UNITS, true)
            && $call->var instanceof MethodCall && $call->var->var instanceof Variable && $call->var->var->name === 'this'
            && $call->var->name instanceof Identifier && $call->var->name->toString() === 'travel') {
            $pinned = $this->walk($call->var->getArgs(), $pinned, $owner);

            return $this->pinCall($args, 0, $pinned, $owner);
        }

        if ($onThis && $name === 'travelBack') {
            return false;
        }

        if ($class !== null && $class->getLast() === 'Wormhole' && $name === 'back') {
            return false;
        }

        if ($class !== null && in_array($class->getLast(), self::CLOCK_CLASSES, true)) {
            if ($name === 'setTestNow' || $name === 'setTestNowAndTimezone') {
                $pinned = $this->walk($args, $pinned, $owner);
                $value = $args[0]->value ?? null;
                if ($value === null || ($value instanceof ConstFetch && strtolower($value->name->toString()) === 'null')) {
                    return false;
                }
                $this->pins++;

                return true;
            }

            if ($name === 'withTestNow') {
                $callback = $args[1]->value ?? null;
                $this->walk($args[0] ?? null, $pinned, $owner);
                if ($callback instanceof Closure || $callback instanceof ArrowFunction) {
                    $this->pins++;
                    $this->walk($callback instanceof Closure ? $callback->stmts : $callback->expr, true, $owner);
                }

                return $pinned;
            }
        }

        // The receiver is evaluated first, then the arguments.
        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            $pinned = $this->walk($call->var, $pinned, $owner);
        }
        $pinned = $this->walk($args, $pinned, $owner);

        if ($name !== null && str_starts_with($name, 'assert') && ! ($call instanceof FuncCall)) {
            $native = $this->nativeClockIn($name, $args);
            if ($native || (! $pinned && self::comparesAClockRead($name, $args))) {
                $this->findings[] = [
                    'test' => $this->test,
                    'file' => $this->classes[$owner]['file'] ?? $this->file,
                    'line' => $call->getStartLine(),
                    'assertion' => $name,
                    'native' => $native,
                ];
            }
        }

        // A helper in the set, walked inline in the state of the call — an
        // assertion helper of the test's own (`assertStamped()`) included.
        if ($name !== null && ($onThis || ($class !== null && in_array($class->toLowerString(), ['self', 'static', 'parent'], true)))) {
            $lookupFrom = $class !== null && $class->toLowerString() === 'parent'
                ? ($this->classes[$owner]['parent'] ?? null)
                : ($class !== null && $class->toLowerString() === 'self' ? $owner : $this->testClass);
            $method = $this->findMethod($lookupFrom, $name);
            if ($method !== null) {
                $key = $method[1].'::'.$name;
                if (! in_array($key, $this->stack, true) && count($this->stack) < 12) {
                    $this->stack[] = $key;
                    $pinned = $this->walk($method[0]->stmts ?? [], $pinned, $method[1]);
                    array_pop($this->stack);
                }
            }
        }

        return $pinned;
    }

    /**
     * @param  list<Arg>  $args
     */
    private function pinCall(array $args, int $callbackAt, bool $pinned, string $owner): bool
    {
        foreach ($args as $i => $arg) {
            if ($i !== $callbackAt) {
                $pinned = $this->walk($arg, $pinned, $owner);
            }
        }

        $this->pins++;
        $callback = $args[$callbackAt]->value ?? null;
        if ($callback === null || ($callback instanceof ConstFetch && strtolower($callback->name->toString()) === 'null')) {
            return true;
        }

        if ($callback instanceof Closure || $callback instanceof ArrowFunction) {
            $this->walk($callback instanceof Closure ? $callback->stmts : $callback->expr, true, $owner);
        }

        // The framework clears the clock when the callback returns.
        return false;
    }

    /**
     * @param  list<Arg>  $args
     */
    private static function comparesAClockRead(string $name, array $args): bool
    {
        foreach (self::comparedArguments($name, $args) as $arg) {
            if (in_array($name, self::PREDICATE, true) ? self::isEqualityWithAClockRead($arg) : self::readsClock($arg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Arg>  $args
     */
    private function nativeClockIn(string $name, array $args): bool
    {
        foreach (self::comparedArguments($name, $args) as $arg) {
            if (in_array($name, self::PREDICATE, true) ? self::isEqualityWithAClockRead($arg, true) : self::readsClock($arg, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Arg>  $args
     * @return list<Expr>
     */
    private static function comparedArguments(string $name, array $args): array
    {
        $positions = self::EQUALITY[$name] ?? (in_array($name, self::PREDICATE, true) ? [0] : []);
        $compared = [];
        foreach ($args as $i => $arg) {
            if ($arg->unpack) {
                continue;
            }
            if ($arg->name !== null ? $arg->name->toString() !== 'message' && $positions !== [] : in_array($i, $positions, true)) {
                $compared[] = $arg->value;
            }
        }

        return $compared;
    }

    private static function isEqualityWithAClockRead(Expr $expr, bool $nativeOnly = false): bool
    {
        if ($expr instanceof Expr\BooleanNot) {
            return self::isEqualityWithAClockRead($expr->expr, $nativeOnly);
        }

        if ($expr instanceof BinaryOp\BooleanAnd || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd || $expr instanceof BinaryOp\LogicalOr) {
            return self::isEqualityWithAClockRead($expr->left, $nativeOnly) || self::isEqualityWithAClockRead($expr->right, $nativeOnly);
        }

        if ($expr instanceof BinaryOp\Identical || $expr instanceof BinaryOp\NotIdentical
            || $expr instanceof BinaryOp\Equal || $expr instanceof BinaryOp\NotEqual) {
            return self::readsClock($expr->left, $nativeOnly) || self::readsClock($expr->right, $nativeOnly);
        }

        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) && $expr->name instanceof Identifier
            && preg_match(self::CARBON_EQUALITY, $expr->name->toString())) {
            if (! $nativeOnly && ! $expr->isFirstClassCallable() && $expr->getArgs() === [] && preg_match(self::IMPLICIT, $expr->name->toString())) {
                return true;
            }
            if (self::readsClock($expr->var, $nativeOnly)) {
                return true;
            }
            foreach ($expr->isFirstClassCallable() ? [] : $expr->getArgs() as $arg) {
                if (self::readsClock($arg->value, $nativeOnly)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does a clock read flow into this value? With $nativeOnly, only PHP's own clock counts.
     */
    public static function readsClock(?Node $expr, bool $nativeOnly = false): bool
    {
        if ($expr === null) {
            return false;
        }

        if ($expr instanceof FuncCall) {
            $fn = $expr->name instanceof Name ? strtolower($expr->name->getLast()) : null;
            $argc = $expr->isFirstClassCallable() ? -1 : count($expr->getArgs());
            if (! $nativeOnly && in_array($fn, ['now', 'today'], true) && $argc >= 0) {
                return true;
            }
            if (in_array($fn, ['time', 'microtime'], true) && $argc >= 0
                || in_array($fn, ['date', 'gmdate', 'idate'], true) && $argc === 1
                || in_array($fn, ['getdate', 'localtime', 'mktime', 'gmmktime'], true) && $argc === 0) {
                return true;
            }
            if ($argc > 0) {
                foreach ($expr->getArgs() as $arg) {
                    if (self::readsClock($arg->value, $nativeOnly)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($expr instanceof StaticCall) {
            $class = $expr->class instanceof Name ? $expr->class->getLast() : null;
            if (! in_array($class, self::CLOCK_CLASSES, true) || $expr->isFirstClassCallable()) {
                return false;
            }
            $method = $expr->name instanceof Identifier ? $expr->name->toString() : null;
            $args = $expr->getArgs();
            if (! $nativeOnly && in_array($method, self::CLOCK_STATICS, true)) {
                return true;
            }
            if (! $nativeOnly && in_array($method, ['parse', 'make', 'create'], true)
                && ($args === [] || self::isRelativeWord($args[0]->value))) {
                return $method !== 'create' || $args === [];
            }
            foreach ($args as $arg) {
                if (self::readsClock($arg->value, $nativeOnly)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof New_) {
            $class = $expr->class instanceof Name ? $expr->class->getLast() : null;
            $args = $expr->getArgs();
            $relative = $args === [] || self::isRelativeWord($args[0]->value);
            if (in_array($class, ['DateTime', 'DateTimeImmutable'], true)) {
                return $relative;
            }
            if (in_array($class, ['Carbon', 'CarbonImmutable'], true)) {
                return ! $nativeOnly && $relative;
            }

            return false;
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            if (self::readsClock($expr->var, $nativeOnly)) {
                return true;
            }
            if (! $expr->name instanceof Identifier || $expr->isFirstClassCallable()) {
                return false;
            }
            $method = $expr->name->toString();
            if (! $nativeOnly && $expr->getArgs() === [] && preg_match(self::IMPLICIT, $method)) {
                return true;
            }
            if (preg_match(self::COMBINE, $method)) {
                foreach ($expr->getArgs() as $arg) {
                    if (self::readsClock($arg->value, $nativeOnly)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            if (! $nativeOnly && $expr->name instanceof Identifier && $expr->name->toString() === 'age') {
                return true;
            }

            return self::readsClock($expr->var, $nativeOnly);
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction || $expr instanceof Variable
            || $expr instanceof Expr\Assign || $expr instanceof Expr\Isset_ || $expr instanceof Expr\Empty_
            || $expr instanceof Expr\Instanceof_ || $expr instanceof Expr\ClassConstFetch || $expr instanceof Expr\StaticPropertyFetch) {
            return false;
        }

        if ($expr instanceof Expr\Array_ || $expr instanceof Node\ArrayItem || $expr instanceof Expr\ArrayDimFetch
            || $expr instanceof BinaryOp || $expr instanceof Expr\Cast || $expr instanceof Expr\Ternary
            || $expr instanceof Expr\Match_ || $expr instanceof Node\MatchArm || $expr instanceof Node\Scalar\InterpolatedString
            || $expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus || $expr instanceof Expr\Clone_) {
            foreach ($expr->getSubNodeNames() as $name) {
                $child = $expr->$name;
                foreach (is_array($child) ? $child : [$child] as $part) {
                    if ($part instanceof Node && ! $part instanceof InterpolatedStringPart && self::readsClock($part, $nativeOnly)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function isRelativeWord(Expr $expr): bool
    {
        return $expr instanceof String_ && in_array(strtolower(trim($expr->value)), self::RELATIVE_WORDS, true);
    }
}
