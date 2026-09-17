<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The files an agent reads first say what this repository is.
 *
 * ---------------------------------------------------------------------------
 * What they used to say
 * ---------------------------------------------------------------------------
 *
 * `CLAUDE.md` and `AGENTS.md` were byte-identical copies of a scaffolding
 * stub that had been in the tree since the first commit. It described no part
 * of this application. What it did contain was instructions to detect the
 * operating system and pipe a remote script into a shell to install PHP, and
 * then to add a package and run its installer "before making application
 * changes" — so the first thing any agent was told to do was mutate the
 * dependency graph of a repository whose lockfile is reviewed, and whose CI
 * installs from it.
 *
 * An instruction file is executable in the only sense that matters: something
 * reads it and acts on it. So it is held to the same standard as the rest of
 * the tree.
 *
 * ---------------------------------------------------------------------------
 * What this asserts, and what it deliberately does not
 * ---------------------------------------------------------------------------
 *
 * Only the active instruction files are scanned — not `docs/`, which records
 * history and quotes things that should not be run, and not the commit that
 * removed the stub. A gate that read the written record would fail on an
 * accurate account of the defect it exists to prevent.
 *
 * The banned list is dependency mutation and remote installers, and it stops
 * there. `composer install` and `npm ci` are how this repository is set up
 * from its lockfiles and are exactly what CI runs, so banning them would make
 * the instructions less useful to be safer about nothing. Nor is
 * `artisan <something>:install` banned: `horizon:install` and
 * `migrate:install` are real commands here, and a pattern broad enough to
 * catch a scaffolding installer catches those too.
 */
final class TheAgentInstructionsDescribeThisRepositoryTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function instructionFiles(): array
    {
        return [
            __DIR__.'/../../AGENTS.md',
            __DIR__.'/../../CLAUDE.md',
        ];
    }

    #[Test]
    public function both_files_exist_and_are_the_same_text(): void
    {
        foreach (self::instructionFiles() as $path) {
            $this->assertFileExists($path);
        }

        /*
         * Two files because two agent tools look for two names, and one text
         * because guidance that disagrees with itself is worse than none. Kept
         * identical by this assertion rather than by remembering.
         */
        $this->assertSame(
            file_get_contents(self::instructionFiles()[0]),
            file_get_contents(self::instructionFiles()[1]),
            'AGENTS.md and CLAUDE.md have drifted apart. They are one document under two names.',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function commandsAnInstructionFileMustNotCarry(): iterable
    {
        yield 'adding a dependency' => ['composer require', 'adds a package to a reviewed lockfile'];
        yield 'removing a dependency' => ['composer remove', 'removes a package from a reviewed lockfile'];
        yield 'updating dependencies' => ['composer update', 'rewrites the lockfile CI installs from'];
        yield 'uninstalling a node package' => ['npm uninstall', 'rewrites package-lock.json'];
        yield 'saving a node dependency' => ['npm install --save', 'rewrites package-lock.json'];
        yield 'saving a node dev dependency' => ['npm install -D', 'rewrites package-lock.json'];
        yield 'the short node install' => ['npm i ', 'adds a package without saying so'];
        yield 'a remote script into bash' => ['| bash', 'runs code nobody in this repository has read'];
        yield 'a remote script into sh' => ['| sh', 'runs code nobody in this repository has read'];
        yield 'a downloaded string' => ['DownloadString', 'runs code nobody in this repository has read'];
        yield 'an expression from the network' => ['Invoke-Expression', 'runs code nobody in this repository has read'];
        yield 'the scaffolding stub' => ['laravel-boost-guidelines', 'is the stub these files replaced'];
        yield 'the remote php installer' => ['php.new', 'is a remote installer piped into a shell'];
    }

    #[Test]
    #[DataProvider('commandsAnInstructionFileMustNotCarry')]
    public function the_instructions_tell_nobody_to_change_what_is_installed(string $needle, string $why): void
    {
        foreach (self::instructionFiles() as $path) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                (string) file_get_contents($path),
                sprintf('%s names `%s`, which %s.', basename($path), $needle, $why),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function factsAnAgentNeedsBeforeTouchingAnything(): iterable
    {
        // Each of these is something an agent gets wrong without being told,
        // and each was got wrong here before it was written down.
        yield 'where business code lives' => ['src/Modules'];
        yield 'which database the tests use' => ['APP_ENV=testing'];
        yield 'that concurrent test runs collide' => ['two `php artisan test`'];
        yield 'that the architecture tests are gates' => ['tests/Architecture/'];
        yield 'that a simulator is not evidence' => ['controlled'];
        yield 'that no real provider is verified' => ['READY_TO_SELL'];
        yield 'that secrets stay out of the tree' => ['Secrets never enter Git'];
    }

    #[Test]
    #[DataProvider('factsAnAgentNeedsBeforeTouchingAnything')]
    public function the_instructions_are_about_this_application(string $needle): void
    {
        /*
         * The banned-command assertions above are all satisfied by an empty
         * file. This is the other half: guidance that says nothing passes a
         * prohibition and helps nobody, and an empty CLAUDE.md is how the
         * stub would most plausibly have been "removed".
         */
        $this->assertStringContainsString(
            $needle,
            (string) file_get_contents(self::instructionFiles()[0]),
            sprintf('The agent instructions no longer mention %s.', $needle),
        );
    }
}
