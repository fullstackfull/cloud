<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
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
 * Every name it matches — function, method, property, constant, variable,
 * attribute, doc tag, parameter, prefix — and every node type a reading
 * decision turns on is an element of one of the public constants below.
 * What is fixed in code, not in a constant, is the walk's own shape: which
 * node kinds it treats as calls, closures, nested functions and classes, the
 * order it visits a call's receiver, arguments and callback, how a method
 * resolves, how set-up is gathered, what a flow descends into. The gate
 * requires a control for every element of every constant in
 * {@see self::VOCABULARY} and for each behaviour its `STRUCTURAL` list
 * names; nothing else is claimed to be held.
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
 *  - **pins**: `$this->m()` for `m` in {@see self::PIN_METHODS} (with no
 *    callback), `$this->travel($n)->u()` for `u` in {@see self::WORMHOLE_UNITS}
 *    (with no callback), and `C::s($t)` for `C` in {@see self::TEST_NOW_CLASSES}
 *    and `s` in {@see self::TEST_NOW_SETTERS} with an argument that is not
 *    `null`;
 *  - **unpins**: `$this->m()` for `m` in {@see self::UNPIN_METHODS},
 *    `C::m()` for each pair in {@see self::UNPIN_STATICS}, and a
 *    {@see self::TEST_NOW_SETTERS} call with no argument or `null`;
 *  - **the callback forms**: a {@see self::PIN_METHODS} call or a Wormhole unit
 *    given a closure at the callback position walks the closure's body
 *    pinned, and leaves the clock unpinned after it (the framework clears it);
 *    `C::s($t, fn)` for `s` in {@see self::TEST_NOW_SCOPES} walks the closure
 *    pinned and leaves the clock as it found it;
 *  - **helpers**: a call through each receiver in {@see self::HELPER_SCOPES}
 *    (`$this->h()`, `self::h()`, `static::h()`, `parent::h()`) that resolves to
 *    a method in the set is walked inline, in the state of the call, and its
 *    effect on the bit carries back. `$this`/`static` resolve from the class
 *    running the test, `self` from the class that wrote the call, `parent`
 *    from that class's parent;
 *  - **any other closure** is walked in the current state, and what it does to
 *    the bit does not leak out.
 *
 * **Assertions** are calls whose name begins with an element of
 * {@see self::ASSERTION_PREFIXES}, on any receiver or none (PHPUnit's
 * function-style `assertSame()` included). One is
 * a finding when the clock is not pinned there and the compared value reads
 * the clock:
 *
 *  - an **equality** assertion, {@see self::EQUALITY}, which lists each with
 *    the argument positions it compares (a failure message is never read),
 *    with a clock read flowing into a compared argument (a named argument
 *    other than `message` is compared);
 *  - a **predicate** assertion, {@see self::PREDICATE}, whose argument is an
 *    equality: a node in {@see self::PREDICATE_OPERATORS} with a clock read
 *    flowing into either side; a call of a method in
 *    {@see self::PREDICATE_METHODS} (or beginning with an element of
 *    {@see self::PREDICATE_PREFIXES}) that reads the clock as below; through
 *    the nodes in {@see self::PREDICATE_CONNECTIVES} (either side) and
 *    {@see self::PREDICATE_NEGATIONS}.
 *
 * A **clock read** is:
 *
 *  - `f()` for `f` in {@see self::CLOCK_FUNCTIONS};
 *  - `C::s()` for `C` in {@see self::CLOCK_CLASSES}, `s` in
 *    {@see self::CLOCK_STATICS};
 *  - `C::p($literal)` for `p` in {@see self::STATIC_PARSERS} with a relative
 *    literal first;
 *  - `new C()` for `C` in {@see self::CLOCK_CONSTRUCTORS}, with nothing or a
 *    relative literal first;
 *  - `$x->m()` with no argument, for `m` in {@see self::IMPLICIT_METHODS} or
 *    beginning with an element of {@see self::IMPLICIT_PREFIXES}, and `$x->p`
 *    for `p` in {@see self::IMPLICIT_PROPERTIES}.
 *
 * **PHP's own clock**, which freezing does not reach and which is therefore a
 * finding pinned or not: `f(…)` for `f` in {@see self::NATIVE_FUNCTIONS} with
 * the argument count that list gives (`null`: any), and `new C()` for `C` in
 * {@see self::NATIVE_CONSTRUCTORS} with nothing or a relative literal first.
 *
 * A **relative literal** is a plain string literal ({@see self::isRelative()})
 * whose meaning depends on the moment it is read: resolved with PHP's
 * `strtotime()` against two base times a year and some hours apart, the two
 * answers differ (`'now'`, `'today'`, `'+7 days'`, `'tomorrow noon'`,
 * `'next monday'`). One whose answers agree (`'2026-01-01'`,
 * `'@1790505192'`) is absolute, and so is one `strtotime()` cannot parse
 * (`'not a date'`, on which Carbon throws). A blank literal (`''`) is
 * relative given to a constructor ({@see self::CLOCK_CONSTRUCTORS},
 * {@see self::NATIVE_CONSTRUCTORS}: `new Carbon('')` is now) or to a parser in
 * {@see self::BLANK_LITERAL_PARSERS} (`Carbon::parse('')` is now), and
 * absolute given to any other (`Carbon::make('')` is null). An argument that
 * is not a plain literal (a variable, a concatenation, an interpolation,
 * `null`) is never relative, and only the first argument is read.
 *
 * A clock read **flows into** a value when it is the value, or:
 *
 *  - the receiver of a node in {@see self::RECEIVER_FLOWS} (a method chain
 *    `now()->addDays(7)->toDateString()`, a property `now()->timestamp`);
 *  - a child of a node in {@see self::FLOW_NODES} (an array element, an index,
 *    an operand of any binary operator, a cast, a ternary's branch, a `match`
 *    arm, an interpolated part, a unary sign, a clone);
 *  - an argument of a call of a plain function (`sprintf`, `json_encode`), of a
 *    static call on a {@see self::CLOCK_CLASSES} class (`Carbon::parse(now())`),
 *    or of a method in {@see self::COMBINE_METHODS} or beginning with an
 *    element of {@see self::COMBINE_PREFIXES} (`diffInDays(now())`, `eq(now())`).
 *
 * ===========================================================================
 * WHAT IT CANNOT SEE
 * ===========================================================================
 *
 *  - **A clock read held in a variable**, whenever it was read.
 *    `$expected = now()->addDay();` and, later, `assertSame($expected, …)` is
 *    not read. That covers the variable the test handed the code as input
 *    (not the shape), and equally a variable read on an unpinned clock
 *    before or after the code ran and never handed to it, which is the shape
 *    — the code reads the clock at another moment — and is not seen.
 *  - **Two clock reads in the test's own fixture** whose difference the code
 *    reports — `'started_at' => now()->subSeconds(20), 'finished_at' =>
 *    now()`, then an exact duration asserted. No assertion reads the clock,
 *    so nothing here is a finding; `MetricsExpositionTest` carried it until
 *    round six pinned its clock.
 *  - **Control flow.** The walk is in source order; a pin inside an `if`, a
 *    loop, or a `try` counts from its position on whether or not the branch
 *    runs, and a closure's body is judged where it is written, not where it
 *    is called.
 *  - **A clock read passed through any other method call**
 *    (`$this->expectedFor(now())`, `$builder->where('at', now())`) does not
 *    flow; neither does one inside a closure argument.
 *  - **Clock reads spelled any other way**: `Carbon::parse(null)`, a static
 *    reader called through a variable class or an alias, a Carbon macro, a
 *    clock read through `app('clock')` or a service.
 *  - **Code outside the set**: a pin or an assertion in a vendor trait or base
 *    class, a helper reached through a variable (`$helper->check()`) or a
 *    dynamic name, a data provider's values.
 *  - **An unpacked argument** (`assertSame(...$pair)`) is not read.
 *  - **A function or class declared inside a test** (an anonymous class
 *    included) is not walked: its body runs, if at all, somewhere else.
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
    public const array PREDICATE = ['assertTrue', 'assertFalse', 'assertNotTrue', 'assertNotFalse'];

    /** In a predicate, an equality whose sides are read. */
    public const array PREDICATE_OPERATORS = [
        BinaryOp\Identical::class, BinaryOp\NotIdentical::class, BinaryOp\Equal::class, BinaryOp\NotEqual::class,
    ];

    /** In a predicate, nodes either of whose sides may be the equality. */
    public const array PREDICATE_CONNECTIVES = [
        BinaryOp\BooleanAnd::class, BinaryOp\BooleanOr::class, BinaryOp\LogicalAnd::class, BinaryOp\LogicalOr::class,
    ];

    /** In a predicate, nodes whose operand may be the equality. */
    public const array PREDICATE_NEGATIONS = [Expr\BooleanNot::class];

    /** In a predicate, Carbon methods that are an equality. */
    public const array PREDICATE_METHODS = [
        'eq', 'equalTo', 'ne', 'notEqualTo', 'isToday', 'isYesterday', 'isTomorrow', 'isBirthday',
        'isNextWeek', 'isNextMonth', 'isNextQuarter', 'isNextYear', 'isNextDecade', 'isNextCentury', 'isNextMillennium',
        'isLastWeek', 'isLastMonth', 'isLastQuarter', 'isLastYear', 'isLastDecade', 'isLastCentury', 'isLastMillennium',
    ];

    /** In a predicate, prefixes of Carbon methods that are an equality (`isSameDay`, `isCurrentMonth`). */
    public const array PREDICATE_PREFIXES = ['isSame', 'isCurrent'];

    /** Functions that read the clock, which freezing reaches. */
    public const array CLOCK_FUNCTIONS = ['now', 'today'];

    /** Classes, by short name, whose static readers and parsers read the clock. */
    public const array CLOCK_CLASSES = ['Carbon', 'CarbonImmutable', 'Date'];

    /** Static methods that read the clock. */
    public const array CLOCK_STATICS = ['now', 'today', 'yesterday', 'tomorrow'];

    /** Static methods that read the clock when given a relative literal. */
    public const array STATIC_PARSERS = ['parse', 'make'];

    /** Classes, by short name, whose constructor reads the clock given nothing or a relative literal. */
    public const array CLOCK_CONSTRUCTORS = ['Carbon', 'CarbonImmutable'];

    /** Carbon methods that read the clock when given no argument. */
    public const array IMPLICIT_METHODS = [
        'isToday', 'isYesterday', 'isTomorrow', 'isBirthday', 'diffForHumans',
        'isNextWeek', 'isNextMonth', 'isNextQuarter', 'isNextYear', 'isNextDecade', 'isNextCentury', 'isNextMillennium',
        'isLastWeek', 'isLastMonth', 'isLastQuarter', 'isLastYear', 'isLastDecade', 'isLastCentury', 'isLastMillennium',
    ];

    /** Prefixes of Carbon methods that read the clock when given no argument (`isCurrentDay()`, `diffInDays()`). */
    public const array IMPLICIT_PREFIXES = ['isCurrent', 'diffIn', 'floatDiffIn'];

    /** Carbon properties that read the clock. */
    public const array IMPLICIT_PROPERTIES = ['age'];

    /** Carbon methods whose arguments flow into their result. */
    public const array COMBINE_METHODS = [
        'eq', 'equalTo', 'ne', 'notEqualTo', 'isBirthday', 'diffForHumans', 'average', 'max', 'min', 'closest', 'farthest',
    ];

    /** Prefixes of Carbon methods whose arguments flow into their result (`isSameDay`, `diffInDays`). */
    public const array COMBINE_PREFIXES = ['isSame', 'diffIn', 'floatDiffIn'];

    /** PHP's own clock: function => the argument count at which it reads it (`null`: any). */
    public const array NATIVE_FUNCTIONS = [
        'time' => null, 'microtime' => null, 'date' => 1, 'gmdate' => 1, 'idate' => 1, 'getdate' => 0, 'localtime' => 0,
    ];

    /** PHP's own clock: classes whose constructor reads it given nothing or a relative literal. */
    public const array NATIVE_CONSTRUCTORS = ['DateTime', 'DateTimeImmutable'];

    /** Nodes whose receiver (`var`) flows into their value. */
    public const array RECEIVER_FLOWS = [
        MethodCall::class, NullsafeMethodCall::class, PropertyFetch::class, NullsafePropertyFetch::class,
    ];

    /** Nodes any of whose children flow into their value. */
    public const array FLOW_NODES = [
        Expr\Array_::class, Node\ArrayItem::class, Expr\ArrayDimFetch::class, BinaryOp::class, Expr\Cast::class,
        Expr\Ternary::class, Expr\Match_::class, Node\MatchArm::class, Node\Scalar\InterpolatedString::class,
        Expr\UnaryMinus::class, Expr\UnaryPlus::class, Expr\Clone_::class,
    ];

    /** `$this->m()` pins; the value is the position of its optional callback. */
    public const array PIN_METHODS = ['freezeTime' => 0, 'freezeSecond' => 0, 'travelTo' => 1];

    /** Laravel's Wormhole units: `$this->travel($n)->unit()` pins. */
    public const array WORMHOLE_UNITS = [
        'microsecond', 'microseconds', 'millisecond', 'milliseconds', 'second', 'seconds',
        'minute', 'minutes', 'hour', 'hours', 'day', 'days', 'week', 'weeks',
        'month', 'months', 'year', 'years',
    ];

    /** `$this->m()` unpins. */
    public const array UNPIN_METHODS = ['travelBack'];

    /** `Class::method()` unpins, by short class name. */
    public const array UNPIN_STATICS = ['Wormhole' => 'back'];

    /** Classes, by short name, whose test-now setters pin. */
    public const array TEST_NOW_CLASSES = ['Carbon', 'CarbonImmutable', 'Date'];

    /** Static setters that pin given a value and unpin given nothing or `null`. */
    public const array TEST_NOW_SETTERS = ['setTestNow', 'setTestNowAndTimezone'];

    /** Static methods that pin for the callback they are given, then restore the clock. */
    public const array TEST_NOW_SCOPES = ['withTestNow'];

    /**
     * Receivers through which a helper in the set is followed, and the class
     * the helper is looked up from: the class running the test, the class
     * that wrote the call, or that class's parent.
     */
    public const array HELPER_SCOPES = ['this' => 'running', 'self' => 'writing', 'static' => 'running', 'parent' => 'parent'];

    /** The variable a call must be made on to pin, unpin or be followed as a helper. */
    public const array THIS_VARIABLES = ['this'];

    /** `$this->travel($n)`, the receiver of a Wormhole unit. */
    public const array TRAVEL_METHODS = ['travel'];

    /** Constants that, given to a test-now setter or as a pin callback, mean nothing. */
    public const array NULL_CONSTANTS = ['null'];

    /** A call whose name begins with one of these is an assertion. */
    public const array ASSERTION_PREFIXES = ['assert'];

    /** Named arguments that are never compared. */
    public const array MESSAGE_PARAMETERS = ['message'];

    /** A public, non-static method whose name begins with one of these is a test. */
    public const array TEST_PREFIXES = ['test'];

    /** Attributes, by short name, that make a public, non-static method a test. */
    public const array TEST_ATTRIBUTES = ['Test'];

    /** Doc tags that make a public, non-static method a test. */
    public const array TEST_DOC_TAGS = ['@test'];

    /** Attributes, by short name, of a method run before each test. */
    public const array BEFORE_ATTRIBUTES = ['Before'];

    /** Doc tags of a method run before each test. */
    public const array BEFORE_DOC_TAGS = ['@before'];

    /** The set-up method, resolved through the hierarchy. */
    public const array SET_UP_METHODS = ['setUp'];

    /** A trait's set-up method is this prefix and the trait's short name. */
    public const array TRAIT_SET_UP_PREFIXES = ['setUp'];

    /** Method-call nodes: a pin, an unpin, a helper, an implicit or combining Carbon method, a predicate method. */
    public const array METHOD_CALLS = [MethodCall::class, NullsafeMethodCall::class];

    /** Property-fetch nodes, for {@see self::IMPLICIT_PROPERTIES}. */
    public const array PROPERTY_FETCHES = [PropertyFetch::class, NullsafePropertyFetch::class];

    /** Static parsers that read a blank literal (`''`) as now (`Carbon::make('')` is null). */
    public const array BLANK_LITERAL_PARSERS = ['parse'];

    /**
     * Every constant the gate requires a control for, with the kind of
     * control each element needs (`found` or `clean`) and the prefix of its
     * name in the fixture: `<kind>_<prefix>__<element>`.
     *
     * @var array<string, array{0: 'found'|'clean', 1: string}>
     */
    public const array VOCABULARY = [
        'PREDICATE' => ['found', 'predicate_assertion'],
        'PREDICATE_OPERATORS' => ['found', 'predicate_operator'],
        'PREDICATE_CONNECTIVES' => ['found', 'predicate_connective'],
        'PREDICATE_NEGATIONS' => ['found', 'predicate_negation'],
        'PREDICATE_METHODS' => ['found', 'predicate_method'],
        'PREDICATE_PREFIXES' => ['found', 'predicate_prefix'],
        'CLOCK_FUNCTIONS' => ['found', 'clock_function'],
        'CLOCK_CLASSES' => ['found', 'clock_class'],
        'CLOCK_STATICS' => ['found', 'clock_static'],
        'STATIC_PARSERS' => ['found', 'static_parser'],
        'CLOCK_CONSTRUCTORS' => ['found', 'clock_constructor'],
        'IMPLICIT_METHODS' => ['found', 'implicit_method'],
        'IMPLICIT_PREFIXES' => ['found', 'implicit_prefix'],
        'IMPLICIT_PROPERTIES' => ['found', 'implicit_property'],
        'COMBINE_METHODS' => ['found', 'combine_method'],
        'COMBINE_PREFIXES' => ['found', 'combine_prefix'],
        'NATIVE_FUNCTIONS' => ['found', 'native_function'],
        'NATIVE_CONSTRUCTORS' => ['found', 'native_constructor'],
        'RECEIVER_FLOWS' => ['found', 'receiver_flow'],
        'FLOW_NODES' => ['found', 'flow_node'],
        'PIN_METHODS' => ['clean', 'pin_method'],
        'WORMHOLE_UNITS' => ['clean', 'wormhole_unit'],
        'UNPIN_METHODS' => ['found', 'unpin_method'],
        'UNPIN_STATICS' => ['found', 'unpin_static'],
        'TEST_NOW_CLASSES' => ['clean', 'test_now_class'],
        'TEST_NOW_SETTERS' => ['clean', 'test_now_setter'],
        'TEST_NOW_SCOPES' => ['clean', 'test_now_scope'],
        'HELPER_SCOPES' => ['found', 'helper_scope'],
        'THIS_VARIABLES' => ['clean', 'this_variable'],
        'TRAVEL_METHODS' => ['clean', 'travel_method'],
        'NULL_CONSTANTS' => ['found', 'null_constant'],
        'ASSERTION_PREFIXES' => ['found', 'assertion_prefix'],
        'MESSAGE_PARAMETERS' => ['clean', 'message_parameter'],
        'TEST_PREFIXES' => ['found', 'test_prefix'],
        'TEST_ATTRIBUTES' => ['found', 'test_attribute'],
        'TEST_DOC_TAGS' => ['found', 'test_doc_tag'],
        'BEFORE_ATTRIBUTES' => ['clean', 'before_attribute'],
        'BEFORE_DOC_TAGS' => ['clean', 'before_doc_tag'],
        'SET_UP_METHODS' => ['clean', 'set_up_method'],
        'TRAIT_SET_UP_PREFIXES' => ['clean', 'trait_set_up_prefix'],
        'METHOD_CALLS' => ['found', 'method_call'],
        'PROPERTY_FETCHES' => ['found', 'property_fetch'],
        'BLANK_LITERAL_PARSERS' => ['found', 'blank_literal_parser'],
    ];

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
     * Is this a string literal whose meaning depends on when it is read?
     */
    public static function isRelative(Expr $expr, bool $blankIsNow = false): bool
    {
        if (! $expr instanceof String_) {
            return false;
        }

        // strtotime() cannot parse a blank string; the constructors and
        // Carbon::parse() read it as now, Carbon::make() as null.
        if (trim($expr->value) === '') {
            return $blankIsNow;
        }

        $utc = new DateTimeZone('UTC');
        $first = strtotime($expr->value, (new DateTimeImmutable('2020-03-04 05:06:07', $utc))->getTimestamp());
        $second = strtotime($expr->value, (new DateTimeImmutable('2021-03-05 09:10:11', $utc))->getTimestamp());

        return $first !== false && $second !== false && $first !== $second;
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
            if ($expr->isFirstClassCallable() || $fn === null) {
                return false;
            }
            $args = $expr->getArgs();
            if (! $nativeOnly && in_array($fn, self::CLOCK_FUNCTIONS, true)) {
                return true;
            }
            if (array_key_exists($fn, self::NATIVE_FUNCTIONS)
                && (self::NATIVE_FUNCTIONS[$fn] === null || self::NATIVE_FUNCTIONS[$fn] === count($args))) {
                return true;
            }

            return self::anyArgumentReadsClock($args, $nativeOnly);
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
            if (! $nativeOnly && in_array($method, self::STATIC_PARSERS, true) && $args !== []
                && self::isRelative($args[0]->value, in_array($method, self::BLANK_LITERAL_PARSERS, true))) {
                return true;
            }

            return self::anyArgumentReadsClock($args, $nativeOnly);
        }

        if ($expr instanceof New_) {
            $class = $expr->class instanceof Name ? $expr->class->getLast() : null;
            $args = $expr->isFirstClassCallable() ? [] : $expr->getArgs();
            $relative = $args === [] || self::isRelative($args[0]->value, true);
            if (in_array($class, self::NATIVE_CONSTRUCTORS, true)) {
                return $relative;
            }
            if (in_array($class, self::CLOCK_CONSTRUCTORS, true)) {
                return ! $nativeOnly && $relative;
            }

            return false;
        }

        if (self::isOneOf($expr, self::RECEIVER_FLOWS) && self::readsClock($expr->var, $nativeOnly)) {
            return true;
        }

        if (self::isOneOf($expr, self::METHOD_CALLS)) {
            if (! $expr->name instanceof Identifier || $expr->isFirstClassCallable()) {
                return false;
            }
            $method = $expr->name->toString();
            $args = $expr->getArgs();
            if (! $nativeOnly && $args === [] && self::named($method, self::IMPLICIT_METHODS, self::IMPLICIT_PREFIXES)) {
                return true;
            }

            return self::named($method, self::COMBINE_METHODS, self::COMBINE_PREFIXES)
                && self::anyArgumentReadsClock($args, $nativeOnly);
        }

        if (self::isOneOf($expr, self::PROPERTY_FETCHES)) {
            return ! $nativeOnly && $expr->name instanceof Identifier
                && in_array($expr->name->toString(), self::IMPLICIT_PROPERTIES, true);
        }

        if (self::isOneOf($expr, self::FLOW_NODES)) {
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

    private static function isThis(Node $node): bool
    {
        return $node instanceof Variable && in_array($node->name, self::THIS_VARIABLES, true);
    }

    private static function isNull(Node $node): bool
    {
        return $node instanceof ConstFetch && in_array(strtolower($node->name->toString()), self::NULL_CONSTANTS, true);
    }

    private static function isAssertion(string $name): bool
    {
        foreach (self::ASSERTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<class-string>  $types
     */
    private static function isOneOf(Node $node, array $types): bool
    {
        foreach ($types as $type) {
            if ($node instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $prefixes
     */
    private static function named(string $method, array $names, array $prefixes): bool
    {
        if (in_array($method, $names, true)) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if (strlen($method) > strlen($prefix) && str_starts_with($method, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Arg>  $args
     */
    private static function anyArgumentReadsClock(array $args, bool $nativeOnly): bool
    {
        foreach ($args as $arg) {
            if (self::readsClock($arg->value, $nativeOnly)) {
                return true;
            }
        }

        return false;
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

        foreach (self::TEST_PREFIXES as $prefix) {
            if (str_starts_with($method->name->toString(), $prefix)) {
                return true;
            }
        }

        return self::marked($method, self::TEST_ATTRIBUTES, self::TEST_DOC_TAGS);
    }

    /**
     * @param  list<string>  $attributes
     * @param  list<string>  $tags
     */
    private static function marked(ClassMethod $method, array $attributes, array $tags): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (in_array($attr->name->getLast(), $attributes, true)) {
                    return true;
                }
            }
        }

        $doc = (string) $method->getDocComment()?->getText();
        foreach ($tags as $tag) {
            if (preg_match('/'.preg_quote($tag, '/').'\b/', $doc)) {
                return true;
            }
        }

        return false;
    }

    private static function isBefore(ClassMethod $method): bool
    {
        return self::marked($method, self::BEFORE_ATTRIBUTES, self::BEFORE_DOC_TAGS);
    }

    /**
     * The pin state a test of this class starts in.
     */
    private function setUpState(string $class): bool
    {
        $this->test = $class.'::setUp';
        $this->stack = [$this->test];
        $pinned = false;

        foreach (self::SET_UP_METHODS as $name) {
            $setUp = $this->findMethod($class, $name);
            if ($setUp !== null) {
                $pinned = $this->walk($setUp[0]->stmts ?? [], $pinned, $setUp[1]);
            }
        }

        foreach ($this->hierarchyTraits($class) as $trait) {
            $short = substr($trait, (int) strrpos('\\'.$trait, '\\'));
            foreach (self::TRAIT_SET_UP_PREFIXES as $prefix) {
                $method = $this->findMethod($trait, $prefix.$short);
                if ($method !== null) {
                    $pinned = $this->walk($method[0]->stmts ?? [], $pinned, $method[1]);
                }
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

        if (self::isOneOf($node, self::METHOD_CALLS) || $node instanceof StaticCall || $node instanceof FuncCall) {
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
        $onThis = self::isOneOf($call, self::METHOD_CALLS) && self::isThis($call->var);
        $class = $call instanceof StaticCall && $call->class instanceof Name ? $call->class : null;
        $args = $call->isFirstClassCallable() ? [] : $call->getArgs();

        if ($onThis && $name !== null && array_key_exists($name, self::PIN_METHODS)) {
            return $this->pinCall($args, self::PIN_METHODS[$name], $pinned, $owner);
        }

        if ($call instanceof MethodCall && in_array($name, self::WORMHOLE_UNITS, true)
            && $call->var instanceof MethodCall && self::isThis($call->var->var)
            && $call->var->name instanceof Identifier && in_array($call->var->name->toString(), self::TRAVEL_METHODS, true)) {
            $pinned = $this->walk($call->var->getArgs(), $pinned, $owner);

            return $this->pinCall($args, 0, $pinned, $owner);
        }

        if ($onThis && in_array($name, self::UNPIN_METHODS, true)) {
            return false;
        }

        if ($class !== null && (self::UNPIN_STATICS[$class->getLast()] ?? null) === $name) {
            return false;
        }

        if ($class !== null && in_array($class->getLast(), self::TEST_NOW_CLASSES, true)) {
            if (in_array($name, self::TEST_NOW_SETTERS, true)) {
                $pinned = $this->walk($args, $pinned, $owner);
                $value = $args[0]->value ?? null;
                if ($value === null || self::isNull($value)) {
                    return false;
                }
                $this->pins++;

                return true;
            }

            if (in_array($name, self::TEST_NOW_SCOPES, true)) {
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
        if (self::isOneOf($call, self::METHOD_CALLS)) {
            $pinned = $this->walk($call->var, $pinned, $owner);
        }
        $pinned = $this->walk($args, $pinned, $owner);

        if ($name !== null && self::isAssertion($name)) {
            $native = self::comparesAClockRead($name, $args, true);
            if ($native || (! $pinned && self::comparesAClockRead($name, $args, false))) {
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
        $scope = $onThis ? $call->var->name : ($class !== null ? $class->toLowerString() : null);
        if ($name !== null && is_string($scope) && isset(self::HELPER_SCOPES[$scope])) {
            $lookupFrom = match (self::HELPER_SCOPES[$scope]) {
                'parent' => $this->classes[$owner]['parent'] ?? null,
                'writing' => $owner,
                default => $this->testClass,
            };
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
        if ($callback === null || self::isNull($callback)) {
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
    private static function comparesAClockRead(string $name, array $args, bool $nativeOnly): bool
    {
        $predicate = in_array($name, self::PREDICATE, true);
        $positions = self::EQUALITY[$name] ?? ($predicate ? [0] : []);

        foreach ($args as $i => $arg) {
            if ($arg->unpack) {
                continue;
            }
            $compared = $arg->name !== null
                ? ! in_array($arg->name->toString(), self::MESSAGE_PARAMETERS, true) && $positions !== []
                : in_array($i, $positions, true);
            if ($compared && ($predicate ? self::isEqualityWithAClockRead($arg->value, $nativeOnly) : self::readsClock($arg->value, $nativeOnly))) {
                return true;
            }
        }

        return false;
    }

    private static function isEqualityWithAClockRead(Expr $expr, bool $nativeOnly): bool
    {
        if (self::isOneOf($expr, self::PREDICATE_NEGATIONS)) {
            return self::isEqualityWithAClockRead($expr->expr, $nativeOnly);
        }

        if (self::isOneOf($expr, self::PREDICATE_CONNECTIVES)) {
            return self::isEqualityWithAClockRead($expr->left, $nativeOnly) || self::isEqualityWithAClockRead($expr->right, $nativeOnly);
        }

        if (self::isOneOf($expr, self::PREDICATE_OPERATORS)) {
            return self::readsClock($expr->left, $nativeOnly) || self::readsClock($expr->right, $nativeOnly);
        }

        if (self::isOneOf($expr, self::METHOD_CALLS) && $expr->name instanceof Identifier
            && self::named($expr->name->toString(), self::PREDICATE_METHODS, self::PREDICATE_PREFIXES)) {
            return self::readsClock($expr, $nativeOnly);
        }

        return false;
    }
}
