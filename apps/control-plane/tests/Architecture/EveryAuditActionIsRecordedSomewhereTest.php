<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An audit vocabulary word nothing writes is a promise the trail does not keep.
 *
 * The failure this catches is quiet and specific. `customer.suspended` and
 * `payment.refunded` existed in the enum for the whole of Phase 29: the
 * endpoints that suspend an account and move money back out were built, both
 * validated a reason from the operator, one of them echoed that reason back in
 * its response as though it had been kept — and neither wrote a row. Anybody
 * reading the enum would have concluded the platform recorded both.
 *
 * The rule is the same one this codebase applies to classes, methods and
 * events, at the last level where it can still be applied: a capability that
 * is described and cannot happen is worse than one that is missing, because
 * the description is what people rely on.
 */
final class EveryAuditActionIsRecordedSomewhereTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Actions deliberately declared before anything writes them.
     *
     * Empty, and an entry here is a promise to remove it. The reason has to
     * name what will write the row and when, because "we will need it later"
     * is how the two above survived a whole phase.
     *
     * @var array<string, string>
     */
    private const array UNWRITTEN = [];

    #[Test]
    public function every_audit_action_has_something_that_writes_it(): void
    {
        $sources = $this->sources();

        $this->assertNotSame([], $sources, 'The scan read no source files, so it proved nothing.');

        $enum = self::ROOT.'/src/Modules/Audit/Domain/Enums/AuditAction.php';

        $unwritten = [];

        foreach (AuditAction::cases() as $case) {
            if (array_key_exists($case->name, self::UNWRITTEN)) {
                continue;
            }

            $written = false;

            foreach ($sources as $file => $code) {
                // The enum names every case; finding one there proves nothing.
                if ($file === $enum) {
                    continue;
                }

                if (str_contains($code, 'AuditAction::'.$case->name)) {
                    $written = true;

                    break;
                }
            }

            if (! $written) {
                $unwritten[] = $case->value;
            }
        }

        sort($unwritten);

        $this->assertSame([], $unwritten, sprintf(
            "These audit actions are declared and nothing ever writes one:\n  %s\n"
            .'Either the act that should record it is unaudited, or the word should go.',
            implode("\n  ", $unwritten),
        ));
    }

    #[Test]
    public function no_excuse_outlives_its_action(): void
    {
        $names = array_map(static fn (AuditAction $case): string => $case->name, AuditAction::cases());

        $stale = array_diff(array_keys(self::UNWRITTEN), $names);

        $this->assertSame([], array_values($stale), 'These actions are excused and no longer exist.');
    }

    /**
     * @return array<string, string> file => code
     */
    private function sources(): array
    {
        $sources = [];

        foreach (['src', 'app'] as $directory) {
            $path = self::ROOT.'/'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
