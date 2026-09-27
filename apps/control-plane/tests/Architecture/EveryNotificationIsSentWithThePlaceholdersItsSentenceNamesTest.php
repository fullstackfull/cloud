<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Lang;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every notification is rendered, in English and in Arabic, with the data its
 * producer sends, and no `:placeholder` survives.
 *
 * A sentence names its facts as `:placeholders` and the producer supplies
 * them in `data`; RenderNotification fills what it is given and leaves the
 * rest as written. `plan_change_completed` named `:plan` and its producer
 * never sent one, so a customer whose upgrade finished was told
 * ":service is now on the :plan plan" (a residue the round-six fixers
 * recorded). Nothing compared the two.
 *
 * ## What it reads
 *
 * Every file under `src/`, parsed. A producer is a call to a method named
 * `execute` with the named arguments `customerId`, `type` and
 * `idempotencyKey` (NotifyCustomer's signature, which every producer calls
 * by name). For each one it resolves:
 *
 *  - the types it can send, from the `type:` expression: a
 *    `NotificationType::Case`, both arms of a ternary, every arm of a
 *    `match`; a local variable, through the expressions assigned to it in
 *    the enclosing method (directly, or by position in a list assignment
 *    from array literals, through ternaries and match arms); `$this->method(...)`, through that method's
 *    `return` expressions in the same file; and a parameter of the enclosing
 *    method, through every call to that method that passes it, positionally
 *    or by name - `$this->method()` in its own file, or, when it is not
 *    private, `$this->property->method()` anywhere in `src/` where the
 *    property is declared with the method's class;
 *  - the keys it sends, from the `data:` expression: the literal string keys
 *    of an array literal, none when the argument is absent, and a parameter
 *    through the same calls as the type - paired call by call, so each
 *    caller's type is rendered with that caller's data.
 *
 * Anything else - a key that is not a string literal, a spread, a type or
 * data built another way - is reported as unresolved and fails the test, so
 * a producer this cannot read is a red test, not a silent pass. Every type
 * must be sent by at least one producer it read.
 *
 * And a placeholder filled with a literal that cannot be right: a value
 * written in the data array as a string literal - `'key' => '...'` at its
 * top level, nothing else - for a placeholder the type's English or Arabic
 * title or body names, that is empty (the sentence reads a gap: "reinstalled
 * with  and is running") or holds a Latin letter (English text, which the
 * Arabic sentence reads too: "your server"). A translatable phrase is sent as
 * {"en": ..., "ar": ...}, which RenderNotification reads in the reader's
 * language, and a name the customer chose (a label, a domain) is not a
 * literal. For the same placeholders, a value written at the top level of the
 * data array as a literal RenderNotification cannot use is reported too
 * ({@see unusableLiterals()} says exactly which): a literal `null` and an
 * array literal that is not a translated name, both of which it drops, so the
 * placeholder is shown as written; and a translated name that names no `en`
 * or no `ar`, whose reader in that language gets the fallback language's
 * text. A test holds the dropped verdicts against RenderNotification itself.
 *
 * What it does not know: whether a value computed at run time is null, empty
 * or an array RenderNotification drops (then the placeholder survives), what
 * an array with a spread or a computed key holds, or whether a value reads
 * well in the sentence.
 */
final class EveryNotificationIsSentWithThePlaceholdersItsSentenceNamesTest extends TestCase
{
    /** @var array<string, list<Node>> */
    private array $files = [];

    /**
     * Every method call in src/, with the file and method it is made in:
     * read once, because resolving a parameter asks for the calls to a
     * method by name.
     *
     * @var list<array{string, ClassMethod, MethodCall}>
     */
    private array $calls = [];

    #[Test]
    public function every_type_is_rendered_by_its_producers_without_a_placeholder_left(): void
    {
        $this->parseSources();

        $unresolved = [];
        $sent = [];
        $missing = [];
        $literal = [];

        foreach ($this->producers() as [$file, $method, $call]) {
            $args = $this->namedArgs($call);

            foreach ($this->pairs($file, $method, $args['type'], $args['data'] ?? null, 0) as $pair) {
                if (is_string($pair)) {
                    $unresolved[] = $this->where($file, $call).': '.$pair;

                    continue;
                }

                [$types, $keys, $literals, $unusable] = $pair;

                foreach ($types as $case) {
                    $sent[$case] = true;
                    $type = constant(NotificationType::class.'::'.$case);

                    $literal = [...$literal, ...$this->literalFindings($this->where($file, $call), $type, $literals, $unusable)];

                    foreach (['en', 'ar'] as $locale) {
                        foreach (['title', 'body'] as $part) {
                            $line = Lang::get('notifications.'.$type->value.'.'.$part, array_fill_keys($keys, 'x'), $locale);

                            if (is_string($line) && preg_match_all('/:([A-Za-z_]+)/', $line, $left) > 0) {
                                $missing[] = sprintf('%s sends %s (%s %s) without %s', $this->where($file, $call), $type->value, $locale, $part, implode(', ', array_map(static fn (string $p): string => ':'.$p, $left[1])));
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame([], $unresolved, 'A producer this gate cannot read.');
        $this->assertSame([], $missing, 'A notification is sent without a fact its sentence names; the customer reads the placeholder.');
        $this->assertSame([], $literal, 'A notification fills a placeholder with a literal that is empty, English in every language, dropped by RenderNotification, or missing a language.');

        $unsent = array_values(array_diff(array_map(static fn (NotificationType $t): string => $t->name, NotificationType::cases()), array_keys($sent)));
        $this->assertSame([], $unsent, 'A type no producer this gate read sends.');
    }

    /**
     * The literal reading behind the third assertion, on data written here,
     * and held against RenderNotification itself: of the values that can be
     * rendered, the ones reported as dropped are exactly the ones whose
     * `:plan` survives rendering, in English or in Arabic.
     */
    #[Test]
    public function a_literal_the_renderer_cannot_use_is_reported_and_nothing_else(): void
    {
        $rendered = [
            'null' => null,
            'empty' => [],
            'unkeyed' => ['Large', 'كبير'],
            'integer keys' => [3 => 'Large'],
            'not a locale' => ['english' => 'Large', 'arabic' => 'كبير'],
            'null text' => ['en' => 'Large', 'ar' => null],
            'a number' => ['en' => 'Large', 'ar' => 1],
            'true' => ['en' => 'Large', 'ar' => true],
            'nested' => ['en' => ['Large'], 'ar' => 'كبير'],
            'no arabic' => ['en' => 'Large'],
            'a map' => ['en' => 'Large', 'ar' => 'كبير'],
            'a map with a third language' => ['en' => 'Large', 'ar' => 'كبير', 'fr' => 'Grand'],
            'text' => 'Large',
            'a number at the top' => 3,
        ];

        $source = "<?php\nreturn [\n".implode('', array_map(
            static fn (string $key, mixed $value): string => var_export($key, true).' => '.var_export($value, true).",\n",
            array_keys($rendered),
            $rendered,
        ))."'upper-case null' => NULL,\n'unkeyed, written short' => ['Large', 'كبير'],\n"
            ."'a map computed' => ['en' => \$name['en'], 'ar' => \$name['ar']],\n'spread' => [...\$name],\n"
            ."'computed key' => [\$locale => 'Large'],\n'variable' => \$name,\n];\n";

        $return = (new ParserFactory)->createForHostVersion()->parse($source)[0] ?? null;
        $this->assertInstanceOf(Return_::class, $return);
        $this->assertInstanceOf(Array_::class, $return->expr);

        $unusable = self::unusableLiterals($return->expr);

        $this->assertSame(
            ['null', 'empty', 'unkeyed', 'integer keys', 'not a locale', 'null text', 'a number', 'true', 'nested', 'no arabic', 'upper-case null', 'unkeyed, written short'],
            array_keys($unusable),
        );
        $this->assertStringContainsString('reads the fallback', $unusable['no arabic']);

        $this->assertSame(
            ['here sends '.NotificationType::PlanChangeCompleted->value.' with :plan as '.$unusable['null']],
            $this->literalFindings('here', NotificationType::PlanChangeCompleted, [], ['plan' => $unusable['null'], 'not_in_the_sentence' => $unusable['empty']]),
            'A literal the renderer cannot use is reported for a placeholder the sentence names, and only for one.',
        );

        $render = new RenderNotification;

        foreach ($rendered as $key => $value) {
            $notification = (new Notification)->forceFill([
                'type' => NotificationType::PlanChangeCompleted,
                'data' => ['service' => 'Web', 'plan' => $value],
            ]);

            $survives = str_contains($render->execute($notification, 'en')->title, ':plan')
                || str_contains($render->execute($notification, 'ar')->title, ':plan');

            $this->assertSame(
                $survives,
                str_contains($unusable[$key] ?? '', 'drops'),
                sprintf('"%s": RenderNotification %s :plan, and this %s it as dropped.', $key, $survives ? 'leaves' : 'fills', $survives ? 'does not report' : 'reports'),
            );
        }
    }

    /**
     * What the third assertion reports for one producer's type: its string
     * literals that are empty or hold a Latin letter, and its literals
     * {@see unusableLiterals()} finds, each only for a placeholder the type's
     * English or Arabic title or body names.
     *
     * @param  array<string, string>  $literals
     * @param  array<string, string>  $unusable
     * @return list<string>
     */
    private function literalFindings(string $where, NotificationType $type, array $literals, array $unusable): array
    {
        $findings = [];

        foreach ($literals as $key => $value) {
            if (! $this->aSentenceNames($type, $key)) {
                continue;
            }

            if ($value === '') {
                $findings[] = sprintf('%s sends %s with :%s as an empty string; the sentence reads a gap', $where, $type->value, $key);
            } elseif (preg_match('/[A-Za-z]/', $value) === 1) {
                $findings[] = sprintf('%s sends %s with :%s as the English text "%s", read inside the Arabic sentence too', $where, $type->value, $key, $value);
            }
        }

        foreach ($unusable as $key => $why) {
            if ($this->aSentenceNames($type, $key)) {
                $findings[] = sprintf('%s sends %s with :%s as %s', $where, $type->value, $key, $why);
            }
        }

        return $findings;
    }

    private function parseSources(): void
    {
        $parser = (new ParserFactory)->createForHostVersion();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('src')));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->files[$file->getPathname()] = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            }
        }

        $finder = new NodeFinder;

        foreach ($this->files as $path => $ast) {
            foreach ($finder->findInstanceOf($ast, ClassMethod::class) as $method) {
                foreach ($finder->findInstanceOf([$method], MethodCall::class) as $call) {
                    if ($call->name instanceof Identifier) {
                        $this->calls[] = [$path, $method, $call];
                    }
                }
            }
        }
    }

    /**
     * @return list<array{string, ?ClassMethod, MethodCall|Expr\StaticCall}>
     */
    private function producers(): array
    {
        $found = [];

        foreach ($this->callsTo('execute') as [$path, $method, $call]) {
            $names = array_keys($this->namedArgs($call));

            if (array_values(array_intersect(['customerId', 'type', 'idempotencyKey'], $names)) === ['customerId', 'type', 'idempotencyKey']) {
                $found[] = [$path, $method, $call];
            }
        }

        $this->assertGreaterThan(20, count($found), 'The producers were not found, so nothing is measured.');

        return $found;
    }

    /**
     * @return array<string, Expr>
     */
    private function namedArgs(Expr\CallLike $call): array
    {
        $named = [];

        foreach ($call->getArgs() as $arg) {
            if ($arg->name !== null) {
                $named[$arg->name->toString()] = $arg->value;
            }
        }

        return $named;
    }

    /**
     * Each (types, keys) pair the arguments can carry, or a string saying why
     * one could not be read.
     *
     * @return list<array{list<string>, list<string>, array<string, string>, array<string, string>}|string>
     */
    private function pairs(string $file, ?ClassMethod $method, Expr $type, ?Expr $data, int $depth): array
    {
        $typeParam = $this->parameterIndex($method, $type);
        $dataParam = $data === null ? null : $this->parameterIndex($method, $data);

        if (($typeParam !== null || $dataParam !== null) && $method !== null) {
            if ($depth > 2) {
                return ['a parameter passed through more than two methods'];
            }

            $pairs = [];

            foreach ($this->callsTo($method->name->toString()) as [$callerFile, $callerMethod, $call]) {
                if (! $this->reaches($callerFile, $call, $file, $method)) {
                    continue;
                }

                $callerType = $typeParam === null ? $type : $this->argument($call, $typeParam, $method);
                $callerData = $dataParam === null ? $data : $this->argument($call, $dataParam, $method);

                if ($callerType === null) {
                    continue;
                }

                if ($dataParam !== null && $callerData === null) {
                    $callerData = new Array_;
                }

                $pairs = [...$pairs, ...$this->pairs(
                    $typeParam === null ? $file : $callerFile,
                    $typeParam === null ? $method : $callerMethod,
                    $callerType,
                    $callerData,
                    $depth + 1,
                )];
            }

            return $pairs === [] ? ['a parameter no call in src/ passes'] : $pairs;
        }

        $types = $this->types($file, $method, $type);
        $keys = $data === null ? [] : $this->keys($data);

        if (is_string($types)) {
            return [$types];
        }

        if (is_string($keys)) {
            return [$keys];
        }

        return [[$types, $keys, $data === null ? [] : $this->literals($data), $data === null ? [] : self::unusableLiterals($data)]];
    }

    /**
     * The values written as a string literal in the data array, by key. Only
     * a top-level `'key' => 'text'` is read: a value computed any other way
     * (a variable, a call, a `?? ''` fallback, an array) is not.
     *
     * @return array<string, string>
     */
    private function literals(Expr $data): array
    {
        $literals = [];

        if ($data instanceof Array_) {
            foreach ($data->items as $item) {
                if ($item !== null && $item->key instanceof String_ && $item->value instanceof String_) {
                    $literals[$item->key->value] = $item->value->value;
                }
            }
        }

        return $literals;
    }

    /**
     * The values written in the data array, at its top level, as a literal
     * the sentence cannot use, by key, each with why:
     *
     *  - `null` (the constant, in any case): RenderNotification drops a null,
     *    and the placeholder is shown as written;
     *  - an array literal RenderNotification drops, because it is not a
     *    translated name (a non-empty map of two-lowercase-letter locale to
     *    text): an empty array, an item with no key or an integer key, a
     *    key that is not two lowercase letters, or a value written as a
     *    literal that is not a string (`null`, `true`, a number, an array);
     *  - an array literal RenderNotification keeps as a translated name but
     *    that names no `en` or no `ar`: a reader in the missing language
     *    reads the fallback language's text inside their sentence.
     *
     * An array with a spread, or with a key that is not a string literal, is
     * not read. A value computed any other way (a variable, a call, an item
     * whose value is not a literal) is not judged here, except that one item
     * that makes an array dropped is enough whatever the others are.
     *
     * @return array<string, string>
     */
    public static function unusableLiterals(Expr $data): array
    {
        $unusable = [];

        if (! $data instanceof Array_) {
            return [];
        }

        foreach ($data->items as $item) {
            if ($item === null || ! $item->key instanceof String_) {
                continue;
            }

            $why = self::unusable($item->value);

            if ($why !== null) {
                $unusable[$item->key->value] = $why;
            }
        }

        return $unusable;
    }

    private static function unusable(Expr $value): ?string
    {
        if (self::isNullConstant($value)) {
            return 'a literal null, which RenderNotification drops, so the placeholder is shown';
        }

        if (! $value instanceof Array_) {
            return null;
        }

        if ($value->items === []) {
            return 'an empty array, which RenderNotification drops, so the placeholder is shown';
        }

        $locales = [];
        $readable = true;

        foreach ($value->items as $item) {
            if ($item === null || $item->unpack) {
                return null;
            }

            if ($item->key === null || $item->key instanceof Node\Scalar\Int_) {
                return 'an array with an integer key, which is not a translated name; RenderNotification drops it, so the placeholder is shown';
            }

            if (! $item->key instanceof String_) {
                $readable = false;

                continue;
            }

            if (preg_match('/\A[a-z]{2}\z/', $item->key->value) !== 1) {
                return sprintf('an array keyed "%s", which is not a translated name; RenderNotification drops it, so the placeholder is shown', $item->key->value);
            }

            $locales[] = $item->key->value;

            if (self::isNullConstant($item->value) || $item->value instanceof Array_ || $item->value instanceof Node\Scalar\Int_
                || $item->value instanceof Node\Scalar\Float_ || self::isBoolConstant($item->value)) {
                return sprintf('an array whose "%s" is not text, which is not a translated name; RenderNotification drops it, so the placeholder is shown', $item->key->value);
            }
        }

        $absent = array_values(array_diff(['en', 'ar'], $locales));

        if ($readable && $absent !== []) {
            return sprintf('a translated name with no "%s", so a reader in that language reads the fallback language\'s text', implode('" and no "', $absent));
        }

        return null;
    }

    private static function isNullConstant(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    private static function isBoolConstant(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false'], true);
    }

    /**
     * Whether the type's title or body names this placeholder, in English or
     * in Arabic. A literal sent for a placeholder no sentence names is never
     * read, and is not reported.
     */
    private function aSentenceNames(NotificationType $type, string $key): bool
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['title', 'body'] as $part) {
                $line = Lang::get('notifications.'.$type->value.'.'.$part, [], $locale);

                if (is_string($line) && preg_match('/:'.preg_quote($key, '/').'(?![A-Za-z_])/', $line) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>|string
     */
    private function types(string $file, ?ClassMethod $method, Expr $expr, int $depth = 0): array|string
    {
        if ($depth > 6) {
            return 'a type resolved through more than six steps';
        }

        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->class->getLast() === 'NotificationType' && $expr->name instanceof Identifier) {
            return [$expr->name->toString()];
        }

        $branches = match (true) {
            $expr instanceof Ternary => [$expr->if ?? $expr->cond, $expr->else],
            $expr instanceof Match_ => array_map(static fn (Node\MatchArm $arm): Expr => $arm->body, $expr->arms),
            $expr instanceof Variable && is_string($expr->name) && $method !== null => $this->assignedTo($method, $expr->name),
            $expr instanceof MethodCall && $expr->var instanceof Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier => $this->returnsOf($file, $expr->name->toString()),
            default => null,
        };

        if ($branches === null || $branches === []) {
            return 'a type built in a way this does not read ('.$expr::class.')';
        }

        $types = [];

        foreach ($branches as $branch) {
            if ($branch instanceof Expr\ConstFetch && strtolower($branch->name->toString()) === 'null') {
                continue;
            }

            $resolved = $this->types($file, $method, $branch, $depth + 1);

            if (is_string($resolved)) {
                return $resolved;
            }

            $types = [...$types, ...$resolved];
        }

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>|string
     */
    private function keys(Expr $expr): array|string
    {
        if (! $expr instanceof Array_) {
            return 'data built in a way this does not read ('.$expr::class.')';
        }

        $keys = [];

        foreach ($expr->items as $item) {
            if ($item === null || $item->unpack || ! $item->key instanceof String_) {
                return 'a data key that is not a string literal';
            }

            $keys[] = $item->key->value;
        }

        return $keys;
    }

    private function parameterIndex(?ClassMethod $method, Expr $expr): ?int
    {
        if ($method === null || ! $expr instanceof Variable || ! is_string($expr->name)) {
            return null;
        }

        foreach ($method->params as $index => $param) {
            if ($param->var instanceof Variable && $param->var->name === $expr->name) {
                // A parameter the method reassigns is a local, read there.
                return $this->assignedTo($method, $expr->name) === [] ? $index : null;
            }
        }

        return null;
    }

    private function argument(Expr\CallLike $call, int $index, ClassMethod $callee): ?Expr
    {
        $name = $callee->params[$index]->var instanceof Variable ? $callee->params[$index]->var->name : null;

        foreach ($call->getArgs() as $position => $arg) {
            if ($arg instanceof Arg && (($arg->name !== null && $arg->name->toString() === $name) || ($arg->name === null && $position === $index))) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * @return list<Expr>
     */
    private function assignedTo(ClassMethod $method, string $variable): array
    {
        $assigned = [];

        foreach ((new NodeFinder)->findInstanceOf([$method], Assign::class) as $assign) {
            if ($assign->var instanceof Variable && $assign->var->name === $variable) {
                $assigned[] = $assign->expr;
            }

            // `[$type, $key] = match (...) { ... => [NotificationType::X, ...] }`
            if ($assign->var instanceof Array_ || $assign->var instanceof Expr\List_) {
                foreach ($assign->var->items as $position => $item) {
                    if ($item !== null && $item->key === null && $item->value instanceof Variable && $item->value->name === $variable) {
                        $assigned = [...$assigned, ...$this->elementsAt($assign->expr, $position)];
                    }
                }
            }
        }

        return $assigned;
    }

    /**
     * The expressions at this position of every array literal the expression
     * can produce, through ternaries and match arms.
     *
     * @return list<Expr>
     */
    private function elementsAt(Expr $expr, int $position): array
    {
        return match (true) {
            $expr instanceof Array_ => isset($expr->items[$position]) && $expr->items[$position] !== null ? [$expr->items[$position]->value] : [],
            $expr instanceof Match_ => array_merge(...array_map(fn (Node\MatchArm $arm): array => $this->elementsAt($arm->body, $position), $expr->arms)),
            $expr instanceof Ternary => [...$this->elementsAt($expr->if ?? $expr->cond, $position), ...$this->elementsAt($expr->else, $position)],
            default => [$expr],
        };
    }

    /**
     * @return list<Expr>
     */
    private function returnsOf(string $file, string $methodName): array
    {
        $returns = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($this->files[$file], ClassMethod::class) as $method) {
            if ($method->name->toString() === $methodName) {
                foreach ($finder->findInstanceOf([$method], Return_::class) as $return) {
                    if ($return->expr !== null) {
                        $returns[] = $return->expr;
                    }
                }
            }
        }

        return $returns;
    }

    /**
     * @return list<array{string, ?ClassMethod, MethodCall}>
     */
    private function callsTo(string $methodName): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $entry): bool => $entry[2]->name instanceof Identifier && $entry[2]->name->toString() === $methodName,
        ));
    }

    /**
     * Whether this call is a call to that method: `$this->method()` in the
     * method's own file, or - for a method that is not private -
     * `$this->property->method()` where the caller declares the property
     * (promoted or not) with the method's class as its type. Calls made any
     * other way are not followed.
     */
    private function reaches(string $callerFile, MethodCall $call, string $calleeFile, ClassMethod $callee): bool
    {
        if ($call->var instanceof Variable && $call->var->name === 'this') {
            return $callerFile === $calleeFile;
        }

        if ($callee->isPrivate() || ! $call->var instanceof Expr\PropertyFetch
            || ! $call->var->var instanceof Variable || $call->var->var->name !== 'this'
            || ! $call->var->name instanceof Identifier) {
            return false;
        }

        $property = $call->var->name->toString();
        $class = (new NodeFinder)->findFirstInstanceOf($this->files[$calleeFile], Node\Stmt\Class_::class);
        $calleeClass = $class?->name?->toString();

        foreach ((new NodeFinder)->findInstanceOf($this->files[$callerFile], Node\Param::class) as $param) {
            if ($param->flags !== 0 && $param->var instanceof Variable && $param->var->name === $property
                && $param->type instanceof Name && $param->type->getLast() === $calleeClass) {
                return true;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($this->files[$callerFile], Node\Stmt\Property::class) as $declared) {
            foreach ($declared->props as $prop) {
                if ($prop->name->toString() === $property && $declared->type instanceof Name && $declared->type->getLast() === $calleeClass) {
                    return true;
                }
            }
        }

        return false;
    }

    private function where(string $file, Node $node): string
    {
        return str_replace(base_path().'/', '', $file).':'.$node->getStartLine();
    }
}
