<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
 * The sweep reads every directory of this application that ships PHP — `src/`
 * and `app/` (where console commands live), and `bootstrap/`, `config/`,
 * `database/` and `routes/` — not only the one a second caller is expected in.
 */
final class TheSubnetRegistrationLockHasOneOwnerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const string OWNER = 'src/Modules/Infrastructure/Application/Actions/RegisterSubnet.php';

    private const string CONSTANT = 'REGISTRATION_LOCK';

    /** Every directory of the application that ships PHP. */
    private const array PRODUCTION = ['app', 'bootstrap', 'config', 'database', 'routes', 'src'];

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

        foreach (self::PRODUCTION as $directory) {
            foreach ($this->phpFilesUnder($directory) as $relative) {
                $read[] = $relative;

                if ($relative === self::OWNER) {
                    continue;
                }

                if (self::names((string) file_get_contents(self::ROOT.'/'.$relative), $key)) {
                    $offenders[] = $relative;
                }
            }
        }

        // Not vacuous: the sweep reached the console commands, which is where
        // a bulk importer — the likeliest second caller — would live.
        $this->assertNotSame(
            [],
            array_filter($read, static fn (string $path): bool => str_starts_with($path, 'app/Console/Commands/')),
            'The sweep did not read app/Console/Commands.',
        );

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

    /** Whether code — not prose — names the constant, or spells its key as a literal. */
    private static function names(string $source, string $key): bool
    {
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
     * @return list<string> paths relative to the application root
     */
    private function phpFilesUnder(string $directory): array
    {
        $root = self::ROOT.'/'.$directory;

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $directory.'/'.substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($files);

        return $files;
    }
}
