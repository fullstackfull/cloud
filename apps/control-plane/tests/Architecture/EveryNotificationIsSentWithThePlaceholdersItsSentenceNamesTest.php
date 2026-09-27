<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Lang;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
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
 * literal.
 *
 * What it does not know: whether a value computed at run time is null or
 * empty (RenderNotification drops a null, and the placeholder then
 * survives), or whether it reads well in the sentence.
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

                [$types, $keys, $literals] = $pair;

                foreach ($types as $case) {
                    $sent[$case] = true;
                    $type = constant(NotificationType::class.'::'.$case);

                    foreach ($literals as $key => $value) {
                        if (! $this->aSentenceNames($type, $key)) {
                            continue;
                        }

                        if ($value === '') {
                            $literal[] = sprintf('%s sends %s with :%s as an empty string; the sentence reads a gap', $this->where($file, $call), $type->value, $key);
                        } elseif (preg_match('/[A-Za-z]/', $value) === 1) {
                            $literal[] = sprintf('%s sends %s with :%s as the English text "%s", read inside the Arabic sentence too', $this->where($file, $call), $type->value, $key, $value);
                        }
                    }

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
        $this->assertSame([], $literal, 'A notification fills a placeholder with a literal that is empty, or English in every language.');

        $unsent = array_values(array_diff(array_map(static fn (NotificationType $t): string => $t->name, NotificationType::cases()), array_keys($sent)));
        $this->assertSame([], $unsent, 'A type no producer this gate read sends.');
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
     * @return list<array{list<string>, list<string>, array<string, string>}|string>
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

        return [[$types, $keys, $data === null ? [] : $this->literals($data)]];
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
