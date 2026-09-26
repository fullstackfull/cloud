<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use Illuminate\View\Compilers\BladeCompiler;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The lock that serialises subnet registration is taken in one place.
 *
 * RegisterSubnet takes an advisory lock, reads every registered block and
 * refuses an overlap, and the three are one unit: the lock is only worth
 * anything because the read and the write happen under it. A second site
 * taking the same key — a bulk importer is the likeliest, and a console
 * command the likeliest home for one — would be a second writer with its own
 * copy of the overlap rule, and the copy that disagrees is the one that hands
 * one address to two customers. So a second use site is a decision somebody
 * has to take out loud, by changing this test.
 *
 * ---------------------------------------------------------------------------
 * Why this counts tokens, not text
 * ---------------------------------------------------------------------------
 *
 * The first attempt at this claim was a sentence in the owner's own docblock
 * stating what a grep for the key over that file answers. Writing the sentence
 * named the key, and that changed the answer: a self-referential grep is a
 * sentence that falsifies itself on write. A gate that reads prose punishes
 * the prose that explains it.
 *
 * So every count here is taken from PHP's own token stream with comments and
 * docblocks removed. A use site is the constant's name after `::`, whichever
 * way the class is spelled — `self::`, `static::` or the class name are the
 * same use to PHP, and a gate that matched one spelling would go green with a
 * second use site sitting in the file. The third row holds that property
 * itself, against sources this test writes.
 *
 * ---------------------------------------------------------------------------
 * What the sweep reads
 * ---------------------------------------------------------------------------
 *
 * Every file under this application's root that ships PHP, except in the
 * directories NOT_SHIPPED names, each with its reason. The reach is stated as
 * what is left out rather than what is let in, because a list of what to read
 * goes stale the way such lists do: this one named six directories while
 * `lang/`, `public/` and `resources/` shipped PHP too — the reference topology
 * loader's data file among them — as did `artisan` at the root, and a key
 * named in any of them left this gate green. A directory added later is read
 * without anybody having to remember it.
 *
 * A file ships PHP when it ends in `.php` or opens as a PHP script, which is
 * how `artisan` is written. A Blade template is read as the PHP Blade compiles
 * it to: its code sits in directives and echoes that the tokenizer would
 * otherwise take for HTML, and its `{{-- --}}` comments go the way of any
 * other comment. That is why this is a Laravel test — the compiler is the
 * application's own, with whatever directives the application registers.
 */
final class TheSubnetRegistrationLockHasOneOwnerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const string OWNER = 'src/Modules/Infrastructure/Application/Actions/RegisterSubnet.php';

    private const string CONSTANT = 'REGISTRATION_LOCK';

    /**
     * What the sweep does not read, and why none of it is this application's
     * own shipped code.
     *
     *  - `node_modules`, `vendor`: npm's and Composer's, not this
     *    application's.
     *  - `storage`: what the running application writes — logs, caches, and
     *    Blade compiled from `resources/views`, which is read at its source.
     *  - `tests`: not shipped; and this gate names the constant itself.
     *  - `tools`: developer tooling, and PHPStan's own `vendor/` once it is
     *    installed. Nothing in it is deployed or loaded by the application.
     */
    private const array NOT_SHIPPED = ['node_modules', 'storage', 'tests', 'tools', 'vendor'];

    #[Test]
    public function the_owner_declares_the_lock_once_and_takes_it_at_exactly_one_site(): void
    {
        $code = $this->read(self::OWNER);

        $this->assertSame(1, self::declarationsIn($code), 'RegisterSubnet does not declare the registration lock exactly once.');
        $this->assertSame(1, self::useSitesIn($code), 'The registration lock is used at more than one site, or at none.');
        $this->assertStringContainsString(
            'pg_advisory_xact_lock',
            self::codeOf($code),
            'The owner no longer takes a transaction-scoped advisory lock. A session lock outlives a refused '
            .'registration and holds every later one behind a connection that has moved on.',
        );
    }

    #[Test]
    public function no_other_production_file_names_the_lock_or_its_key(): void
    {
        $key = self::keyDeclaredIn($this->read(self::OWNER));
        $this->assertNotSame('', $key, 'Could not read the lock key from its declaration; nothing below would mean anything.');

        $read = [];
        $offenders = [];

        foreach ($this->filesThatShipPhp() as $relative) {
            $read[] = $relative;

            if ($relative === self::OWNER) {
                continue;
            }

            if ($this->names($relative, (string) file_get_contents(self::ROOT.'/'.$relative), $key)) {
                $offenders[] = $relative;
            }
        }

        // Not vacuous: the sweep reached the console commands, which is where
        // a bulk importer — the likeliest second caller — would live.
        $this->assertNotSame(
            [],
            array_filter($read, static fn (string $path): bool => str_starts_with($path, 'app/Console/Commands/')),
            'The sweep did not read app/Console/Commands.',
        );

        // And it reached what a hand-kept list of directories once left out:
        // lang/, public/ and resources/ ship PHP too, the reference topology
        // loader's data file among them, and artisan is PHP without the suffix.
        foreach (['artisan', 'lang/', 'public/', 'resources/'] as $reach) {
            $this->assertNotSame(
                [],
                array_filter($read, static fn (string $path): bool => $path === $reach || str_starts_with($path, $reach)),
                sprintf('The sweep did not read %s.', $reach),
            );
        }

        $this->assertSame(
            [],
            $offenders,
            'The subnet registration lock, or its key, is named outside RegisterSubnet. A second site taking it is '
            .'a second writer with its own copy of the overlap rule; route it through RegisterSubnet instead.',
        );
    }

    #[Test]
    public function the_count_is_read_from_the_code_and_not_from_what_is_written_about_it(): void
    {
        $declaration = '<?php final class Owner { private const string '.self::CONSTANT." = 'k';\n";
        $use = '    public function f(): void { lock([self::'.self::CONSTANT."]); }\n";

        $this->assertSame(1, self::useSitesIn($declaration.$use.'}'));

        // Prose about the key — a comment, a line comment, a docblock — is
        // not a use of it, so explaining the lock cannot redden this gate.
        $this->assertSame(1, self::useSitesIn(
            $declaration
            .'    /** Taken once: {@see self::'.self::CONSTANT."} */\n"
            .$use
            .'    // self::'.self::CONSTANT.' and static::'.self::CONSTANT." are the same use\n"
            .'    /* Owner::'.self::CONSTANT." */\n"
            .'}',
        ));

        // A second site is a second site however the class is spelled.
        $this->assertSame(2, self::useSitesIn(
            $declaration.$use.'    public function g(): void { lock([static::'.self::CONSTANT."]); }\n}",
        ));
        $this->assertSame(2, self::useSitesIn(
            $declaration.$use.'    public function g(): void { lock([Owner::'.self::CONSTANT."]); }\n}",
        ));

        // And the declaration is not a use.
        $this->assertSame(0, self::useSitesIn($declaration.'}'));

        // The same holds for naming the lock in another file, and a Blade
        // template is read as the PHP it compiles to: code in a directive or
        // an echo names the lock, a Blade comment or the page's text does not.
        $key = 'the-registration-key';
        $php = '<?php ';
        $this->assertTrue($this->names('x.php', $php.'lock([Owner::'.self::CONSTANT.']);', $key));
        $this->assertTrue($this->names('x.php', $php."lock(['".$key."']);", $key));
        $this->assertFalse($this->names('x.php', $php.'// Owner::'.self::CONSTANT.", '".$key."'\n/** ".$key.' */', $key));
        $this->assertTrue($this->names('x.blade.php', '@php lock([Owner::'.self::CONSTANT."]); @endphp\n", $key));
        $this->assertTrue($this->names('x.blade.php', "<p>{{ lock('".$key."') }}</p>\n", $key));
        $this->assertFalse($this->names(
            'x.blade.php',
            '{{-- Owner::'.self::CONSTANT.", '".$key."' --}}\n<p>".self::CONSTANT.' '.$key."</p>\n",
            $key,
        ));
    }

    /**
     * The tokens that are code: no comments, no docblocks, no whitespace.
     *
     * @return list<PhpToken>
     */
    private static function tokensOf(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]),
        ));
    }

    private static function codeOf(string $source): string
    {
        return implode(' ', array_map(static fn (PhpToken $token): string => $token->text, self::tokensOf($source)));
    }

    /** The constant's name directly after `::`, however the class is spelled. */
    private static function useSitesIn(string $source): int
    {
        $tokens = self::tokensOf($source);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if ($token->is(T_DOUBLE_COLON) && isset($tokens[$i + 1]) && $tokens[$i + 1]->text === self::CONSTANT) {
                $count++;
            }
        }

        return $count;
    }

    private static function declarationsIn(string $source): int
    {
        $tokens = self::tokensOf($source);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if ($token->text === self::CONSTANT && isset($tokens[$i + 1]) && $tokens[$i + 1]->text === '='
                && isset($tokens[$i - 1]) && ! $tokens[$i - 1]->is(T_DOUBLE_COLON)) {
                $count++;
            }
        }

        return $count;
    }

    /** The string the constant is declared as, without its quotes. */
    private static function keyDeclaredIn(string $source): string
    {
        $tokens = self::tokensOf($source);

        foreach ($tokens as $i => $token) {
            if ($token->text === self::CONSTANT && ($tokens[$i + 1]->text ?? '') === '='
                && isset($tokens[$i + 2]) && $tokens[$i + 2]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return substr($tokens[$i + 2]->text, 1, -1);
            }
        }

        return '';
    }

    /**
     * Whether code — not prose — names the constant, or spells its key as a
     * literal. A Blade template is compiled first, so that its code is PHP
     * tokens rather than one run of inline HTML.
     */
    private function names(string $relative, string $source, string $key): bool
    {
        if (str_ends_with($relative, '.blade.php')) {
            $source = $this->app->make(BladeCompiler::class)->compileString($source);
        }

        foreach (self::tokensOf($source) as $token) {
            if ($token->text === self::CONSTANT) {
                return true;
            }

            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && str_contains($token->text, $key)) {
                return true;
            }
        }

        return false;
    }

    private function read(string $relative): string
    {
        $path = self::ROOT.'/'.$relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every file under the root that ships PHP, outside NOT_SHIPPED.
     *
     * @return list<string> paths relative to the application root
     */
    private function filesThatShipPhp(): array
    {
        $files = [];

        /** @var SplFileInfo $entry */
        foreach (new FilesystemIterator(self::ROOT) as $entry) {
            $name = $entry->getFilename();

            if (in_array($name, self::NOT_SHIPPED, true)) {
                continue;
            }

            if ($entry->isFile()) {
                if (self::shipsPhp($entry)) {
                    $files[] = $name;
                }

                continue;
            }

            if (! $entry->isDir() || $entry->isLink()) {
                continue;
            }

            $root = $entry->getPathname();

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && self::shipsPhp($file)) {
                    $files[] = $name.'/'.substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }

    /** A `.php` file, or one that opens as a PHP script without the suffix. */
    private static function shipsPhp(SplFileInfo $file): bool
    {
        if ($file->getExtension() === 'php') {
            return true;
        }

        if (! $file->isReadable()) {
            return false;
        }

        $head = (string) file_get_contents($file->getPathname(), false, null, 0, 64);
        $firstLine = strtok($head, "\n");

        return str_starts_with($head, '<?php')
            || (str_starts_with($head, '#!') && is_string($firstLine) && str_contains($firstLine, 'php'));
    }
}
