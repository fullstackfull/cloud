<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;
use SplFileInfo;

/**
 * Every notification the platform declares is one some production code raises
 * — and every sentence written for one belongs to a type that still exists.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * The audit's F-46: of fifty-five declared types, sixteen had customer-facing
 * copy written and translated into English and Arabic, and nothing produced
 * them. The recount against the tree F-46 was repaired on found thirteen, not
 * sixteen — the four backup and restore messages had been wired while F-09
 * and F-10 were closed, and `InvoiceIssued`, which the audit counted as
 * produced, was written only by `E2ESeeder`, and a seeder is not a producer.
 *
 * A declared-and-unproduced notification is a promise the platform does not
 * keep, and it reads as coverage: `NotifyCustomerTest` demanded an English and
 * an Arabic sentence for every one of them, so the suite was green over copy
 * no customer could ever receive. The account-security four were the worst of
 * it — a password change, a second factor switched off, a sign-in from
 * somewhere new — because telling the account holder is how a takeover is
 * noticed, and the platform described doing it and did not.
 *
 * A census in a hand-back rots, and a hand-written list of names is not this
 * test: the next type is covered only if somebody remembers to add a line.
 * So the declared set is read from the enum itself and the producers are found
 * by reading the code.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * **Declared** means a case of {@see NotificationType}, read by reflection.
 *
 * **Produced** means a reference `NotificationType::Case` — resolved through
 * the file's namespace and `use` imports, aliases included — in a PHP file
 * under `src/` or `app/`, other than the enum's own file, standing in a
 * producing position. Tokenised with `PhpToken`, so comments and docblocks are
 * not code. Tests, factories and seeders are not production and are never
 * read; that is what keeps `E2ESeeder` from counting.
 *
 * A reference is a READER, not a producer, when it is:
 *
 *  - an operand of `===`, `!==`, `==` or `!=`;
 *  - a condition of a `match` arm (left of its `=>`);
 *  - a `case` label of a `switch`;
 *  - a member of the haystack literal of `in_array()` or `array_search()`;
 *  - a list member of a `const` or property initialiser — a declared set,
 *    such as a list of types that are emailed. A keyed value there is a
 *    producer, as it is everywhere else.
 *
 * Everything else counts as a producer, which is the conservative direction:
 * an unrecognised reading position hides a dead type, it never calls a live
 * one dead.
 *
 * ===========================================================================
 * WHAT IT DOES NOT SEE
 * ===========================================================================
 *
 *  - **Reachability of the producer.** A producer is a site that names the
 *    type in a producing position. Whether a route, command, listener or
 *    scheduled job ever reaches that site is not asked here: a producer in a
 *    method nothing calls passes. {@see NoDeadCapabilitiesTest} (every action,
 *    job and listener is referenced) and {@see NoDeadMethodsTest} (every public
 *    method is referenced) are the neighbouring gates, and neither of them
 *    proves an entry point reaches the site either — a reference is not a
 *    call path. The census that repaired F-46 traced every producer to its
 *    entry point by hand; this test keeps the half of that which a token
 *    stream can keep.
 *  - **A producing reference that produces nothing.** `$unused =
 *    NotificationType::X;` counts as a producer. The test does not follow the
 *    value to {@see NotifyCustomer}.
 *  - **A type named dynamically.** `NotificationType::from($x)`,
 *    `::tryFrom($x)` and a `cases()` loop are invisible. None exists in
 *    production code today; the Eloquent cast on the notification model is a
 *    read path that hydrates rows something else wrote. A producer spelled
 *    dynamically would make this test fail on a type that IS produced — the
 *    loud direction.
 *  - **The portal.** `apps/web` renders the title and body the API sends, so
 *    there is no per-type string there to check. Its category labels are not
 *    read here.
 *
 * ===========================================================================
 * WHY NOT ONE OF ITS NEIGHBOURS
 * ===========================================================================
 *
 * {@see EveryAuditActionIsRecordedSomewhereTest} asks the same question of the
 * audit vocabulary, with `str_contains`. That would be wrong here, and not in
 * theory: `NotificationType::ServiceProvisioning` is a prefix of
 * `NotificationType::ServiceProvisioningFailed`, so a substring search called
 * the first produced for as long as the second was. The references are
 * therefore matched as tokens.
 *
 * {@see EveryStateAMachineCanEnterHasAProducerTest} classifies positions the
 * same way, but its subject is the destinations of discovered state machines,
 * and this enum is governed by no machine; widening that gate's subject would
 * move another architecture test's subject. Its classifier is private to it,
 * so this file carries its own, smaller one — without the machine discovery,
 * the `->value` projection rules or the query-builder heuristics, because a
 * notification type is never written through a query builder.
 *
 * {@see EveryControllerMethodIsReachableTest} and the capability gates
 * ({@see EveryDeclaredCapabilityHasAConsumerTest}, {@see NoDeadCapabilitiesTest})
 * ask whether a handler or a class is reachable. This asks whether a declared
 * VALUE is ever produced, which none of them can see: every class that could
 * raise a password-change notification was reachable, and none of them did.
 */
final class EveryNotificationTypeHasAProducerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** @var list<string> */
    private const array PRODUCTION = ['src', 'app'];

    /** @var list<string> */
    private const array LOCALES = ['en', 'ar'];

    /** @var list<string> */
    private const array MEMBERSHIP_PREDICATES = ['in_array', 'array_search'];

    /** @var array<string, list<array{string, int, string}>>|null */
    private static ?array $sites = null;

    #[Test]
    public function every_declared_type_is_raised_by_production_code(): void
    {
        $sites = self::sites();

        $this->assertGuards($sites);

        $unproduced = [];

        foreach (NotificationType::cases() as $type) {
            $producers = array_filter(
                $sites[$type->name] ?? [],
                static fn (array $site): bool => $site[2] === 'producer',
            );

            if ($producers === []) {
                $unproduced[] = sprintf('%s (%s)', $type->name, $type->value);
            }
        }

        $this->assertSame([], $unproduced, sprintf(
            "These notification types are declared, and nothing in src/ or app/ raises one:\n  %s\n\n".
            'A declared notification is a promise to tell the customer something. Either raise it where the moment '.
            'happens, or delete the case and its English and Arabic copy — and say why at the site either way.',
            implode("\n  ", $unproduced),
        ));
    }

    #[Test]
    public function every_declared_type_has_copy_in_both_locales(): void
    {
        $missing = [];

        foreach (self::LOCALES as $locale) {
            $copy = $this->copy($locale);

            foreach (NotificationType::cases() as $type) {
                [$group, $key] = explode('.', $type->value, 2);

                foreach (['title', 'body'] as $part) {
                    $line = $copy[$group][$key][$part] ?? null;

                    if (! is_string($line) || trim($line) === '') {
                        $missing[] = "{$locale}: notifications.{$type->value}.{$part}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These notification types have no sentence to send in one language:\n  %s\n\n".
            'RenderNotification falls back to the key itself, so a customer would be shown the key.',
            implode("\n  ", $missing),
        ));
    }

    #[Test]
    public function no_copy_outlives_its_type(): void
    {
        $declared = array_flip(array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases()));
        $orphans = [];
        $messages = 0;

        foreach (self::LOCALES as $locale) {
            foreach ($this->copy($locale) as $group => $entries) {
                if (! is_array($entries)) {
                    continue;
                }

                foreach ($entries as $key => $entry) {
                    // A message is an entry with a title or a body; `action.open`
                    // and `signature` are the mail's furniture, not messages.
                    if (! is_array($entry) || (! isset($entry['title']) && ! isset($entry['body']))) {
                        continue;
                    }

                    $messages++;

                    if (! isset($declared["{$group}.{$key}"])) {
                        $orphans[] = "{$locale}: notifications.{$group}.{$key}";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            count($declared) * count(self::LOCALES),
            $messages,
            'Fewer messages were read from the language files than there are declared types in both languages; the walk is broken.',
        );

        $this->assertSame([], $orphans, sprintf(
            "This copy is written for a notification type that no longer exists:\n  %s\n\n".
            'A type is deleted with its two translations. Copy left behind reads as a message the platform can send.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * A category is a switch on the preferences screen. A category no type
     * belongs to is a switch that controls nothing, offered to every customer.
     */
    #[Test]
    public function every_category_is_the_category_of_some_type(): void
    {
        $used = [];

        foreach (NotificationType::cases() as $type) {
            $used[$type->category()->value] = true;
        }

        $empty = array_values(array_filter(
            array_map(static fn (NotificationCategory $category): string => $category->value, NotificationCategory::cases()),
            static fn (string $category): bool => ! isset($used[$category]),
        ));

        $this->assertSame([], $empty, sprintf(
            "These notification categories hold no type, so the preferences screen offers a switch for nothing:\n  %s",
            implode("\n  ", $empty),
        ));
    }

    /**
     * The classifier, run on source it has never seen, must tell each reading
     * position from a producing one. Without this a classifier that called
     * everything a producer would pass the first test for the wrong reason.
     */
    #[Test]
    public function the_classifier_tells_a_reader_from_a_producer(): void
    {
        $source = <<<'PHP'
            <?php
            namespace Somewhere;

            use Lynomia\Modules\Notifications\Domain\Enums\NotificationType as Type;

            final class Probe
            {
                private const array EMAILED = [Type::PasswordChanged];

                private array $keyed = ['type' => Type::TwoFactorEnabled];

                public function run(object $n): void
                {
                    if ($n->type === Type::TwoFactorDisabled) {}
                    $x = match ($n->type) { Type::NewSignIn => 1, default => Type::InvoiceIssued };
                    switch ($n->type) { case Type::ServiceReady: break; }
                    in_array($n->type, [Type::PaymentFailed], true);
                    $this->notify(type: Type::RefundIssued);
                    // Type::TicketOpened is a comment, and not code.
                }
            }
            PHP;

        $found = [];

        foreach ($this->classify($source) as [$case, , $position]) {
            $found[$case] = $position;
        }

        $this->assertSame([
            'PasswordChanged' => 'declared-set member',
            'TwoFactorEnabled' => 'producer',
            'TwoFactorDisabled' => 'comparison',
            'NewSignIn' => 'match arm condition',
            'InvoiceIssued' => 'producer',
            'ServiceReady' => 'switch case',
            'PaymentFailed' => 'haystack member',
            'RefundIssued' => 'producer',
        ], $found);
    }

    /**
     * @param  array<string, list<array{string, int, string}>>  $sites
     */
    private function assertGuards(array $sites): void
    {
        $this->assertGreaterThan(40, count(NotificationType::cases()), 'Fewer notification types were declared than exist today; the enum was not read.');

        $produced = array_filter($sites, static fn (array $list): bool => array_filter(
            $list,
            static fn (array $site): bool => $site[2] === 'producer',
        ) !== []);

        $this->assertGreaterThan(
            count(NotificationType::cases()) / 2,
            count($produced),
            'Fewer than half of the declared types have a producer; the scan is almost certainly broken rather than the tree.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function copy(string $locale): array
    {
        $path = self::ROOT."/lang/{$locale}/notifications.php";

        $this->assertFileExists($path);

        $copy = require $path;

        $this->assertIsArray($copy, "lang/{$locale}/notifications.php does not return an array.");

        return $copy;
    }

    /**
     * Every classified reference to a NotificationType case in production
     * code, memoised for the process.
     *
     * @return array<string, list<array{string, int, string}>> case name → [file, line, position]
     */
    private static function sites(): array
    {
        if (self::$sites !== null) {
            return self::$sites;
        }

        $test = new self('sites');
        $enumFile = (string) realpath((string) (new ReflectionEnum(NotificationType::class))->getFileName());
        $root = (string) realpath(self::ROOT);
        $sites = [];

        foreach (self::PRODUCTION as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = (string) $file->getRealPath();

                // The enum names every case of itself; finding one there proves nothing.
                if ($file->getExtension() !== 'php' || $path === $enumFile) {
                    continue;
                }

                $source = (string) file_get_contents($path);

                if (! str_contains($source, 'NotificationType')) {
                    continue;
                }

                foreach ($test->classify($source) as [$case, $line, $position]) {
                    $sites[$case][] = [substr($path, strlen($root) + 1), $line, $position];
                }
            }
        }

        return self::$sites = $sites;
    }

    /**
     * Classify every reference to a NotificationType case in one file.
     *
     * @return list<array{string, int, string}> [case name, line, position]
     */
    private function classify(string $source): array
    {
        $enum = NotificationType::class;
        $cases = array_flip(array_map(static fn (NotificationType $type): string => $type->name, NotificationType::cases()));

        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
        ));
        $count = count($tokens);

        $namespace = '';
        $aliases = [];

        /** @var list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}> $stack */
        $stack = [];
        $pendingBody = null;
        $pendingConst = false;
        $init = null;
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $depth = count($stack);

            if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
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

            // A reference: Name :: Case, not a static call and not `::class`.
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                || ! ($tokens[$i + 2] ?? null)?->is(T_STRING)
                || ($tokens[$i + 3] ?? null)?->text === '(') {
                continue;
            }

            $case = $tokens[$i + 2]->text;

            if ($this->resolve($token, $namespace, $aliases) !== $enum || ! isset($cases[$case])) {
                continue;
            }

            $found[] = [$case, $token->line, $this->position($prev, $tokens[$i + 3] ?? null, $stack, $init)];

            $i += 2;
        }

        return $found;
    }

    /**
     * @param  list<array{kind: string, callee: ?string, arg: int, arrow: bool, argOfParent: int}>  $stack
     */
    private function position(?PhpToken $prev, ?PhpToken $next, array $stack, ?int $init): string
    {
        $comparisons = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];

        if ($prev?->is(T_CASE)) {
            return 'switch case';
        }

        if ($prev?->is($comparisons) || $next?->is($comparisons)) {
            return 'comparison';
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

        if ($init !== null && $depth - $arrays === $init && $arrays > 0 && $top !== null && ! $top['arrow']) {
            return 'declared-set member';
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
    private function resolve(PhpToken $token, string $namespace, array $aliases): string
    {
        $name = $token->text;

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];

        if (isset($aliases[$first])) {
            return $aliases[$first].substr($name, strlen($first));
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }
}
