<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Nothing in this codebase may be written, tested, and unreachable.
 *
 * Phase 29 found five provisioning handlers, two hosting actions, an invoice
 * void, an orphan adoption, a hardware sync, a drift review, a panel
 * preflight and a dunning recovery in exactly that state: correct, covered by
 * their own tests, and impossible to reach from the running application. Every
 * one of them presented to a customer or an operator as a working feature.
 *
 * A green suite cannot see this. Each of those had tests, and the tests
 * constructed the class themselves — which proves the class works and proves
 * nothing about whether anything calls it.
 *
 * ---------------------------------------------------------------------------
 * How the reference is counted
 * ---------------------------------------------------------------------------
 *
 * Comments are stripped before searching. Several of these classes were named
 * only in a docblock explaining that they had no caller yet, and a naive grep
 * counts that as a caller — the one place where being generous makes the test
 * useless.
 *
 * Tests are not searched at all, deliberately: a class that only its own test
 * mentions is the exact shape being hunted.
 *
 * Console commands are exempt. Laravel discovers them from the filesystem, so
 * being unreferenced is how a command is *supposed* to look; `php artisan` is
 * their execution path and `schedule:list` is what proves the scheduled ones
 * run.
 */
final class NoDeadCapabilitiesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Classes that legitimately have no caller in application code.
     *
     * An entry here is a promise that something outside this repository
     * invokes the class — and a promise that the gap it leaves is reported
     * honestly rather than papered over. Everything else belongs wired up or
     * deleted.
     *
     * @var list<string>
     */
    private const array INTENTIONALLY_UNREFERENCED = [
    ];

    #[Test]
    public function every_action_job_and_listener_is_reachable_from_the_application(): void
    {
        $capabilities = $this->capabilities();

        $this->assertNotEmpty($capabilities, 'The scan found nothing, so it is not proving anything.');

        $sources = $this->applicationSources();

        $dead = [];

        foreach ($capabilities as $name => $path) {
            if (in_array($name, self::INTENTIONALLY_UNREFERENCED, strict: true)) {
                continue;
            }

            foreach ($sources as $file => $code) {
                if ($file === $path) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($name, '/').'\b/', $code) === 1) {
                    continue 2;
                }
            }

            $dead[] = sprintf('%s (%s)', $name, $path);
        }

        sort($dead);

        $this->assertSame([], $dead, sprintf(
            "These capabilities exist and nothing in the application can reach them.\n"
            ."Each one presents to somebody as a working feature and cannot complete:\n  %s",
            implode("\n  ", $dead),
        ));
    }

    /**
     * Every action, job and listener, by class name.
     *
     * @return array<string, string>
     */
    private function capabilities(): array
    {
        $found = [];

        foreach ($this->phpFiles(self::ROOT.'/src') as $path) {
            $isCapability = str_contains($path, '/Application/Actions/')
                || str_contains($path, '/Jobs/')
                || str_contains($path, '/Application/Listeners/');

            if (! $isCapability) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/^(?:final |abstract |readonly )*class (\w+)/m', $source, $matches) !== 1) {
                continue;
            }

            // Abstract classes are reached through their subclasses, which are
            // themselves scanned.
            if (str_contains($matches[0], 'abstract ')) {
                continue;
            }

            $found[$matches[1]] = $path;
        }

        return $found;
    }

    /**
     * Application source with comments removed.
     *
     * @return array<string, string>
     */
    private function applicationSources(): array
    {
        $sources = [];

        foreach (['/src', '/app', '/routes', '/config', '/bootstrap', '/database'] as $directory) {
            foreach ($this->phpFiles(self::ROOT.$directory) as $path) {
                $sources[$path] = $this->withoutComments((string) file_get_contents($path));
            }
        }

        return $sources;
    }

    private function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }

        return $files;
    }
}
