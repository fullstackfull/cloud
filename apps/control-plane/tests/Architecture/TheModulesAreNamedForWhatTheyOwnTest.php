<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The control centre is three bounded concerns, not one, and none of them is
 * called Estate.
 *
 * ---------------------------------------------------------------------------
 * Why a test rather than a decision somebody remembers
 * ---------------------------------------------------------------------------
 *
 * Because the module was briefly called Estate, and a name that has ever been
 * committed comes back. It comes back in a migration filename somebody copies,
 * in a translation key that reaches the frontend, in a route somebody adds
 * beside an existing one — and each of those is harder to change than the last,
 * because by then a screen or an API consumer depends on it.
 *
 * "Estate" as an English word is fine and predates this phase: Compute,
 * Dedicated and the Horizon config all use it to mean "the machines we have".
 * What must not exist is Estate as a NAME — a namespace, a class, a table, a
 * route, a translation key, a migration.
 *
 * The three concerns are deliberately separate, and this also asserts that:
 *
 *   Infrastructure   the machines and what may be done to them
 *   Providers        the accounts we hold and whether they answer
 *   ProductReadiness whether the platform may sell a thing
 *
 * Control Center is the navigation area the admin UI composes them into. It is
 * not a module, and a module by that name would be the god object all three
 * were split out of.
 */
final class TheModulesAreNamedForWhatTheyOwnTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Places a name becomes permanent: something a person or another system
     * later depends on by that exact spelling.
     *
     * @var list<string>
     */
    private const array NAMING_SURFACES = [
        'src',
        'database/migrations',
        'database/factories',
        'routes',
    ];

    #[Test]
    public function no_namespace_class_or_file_is_named_estate(): void
    {
        $violations = [];

        foreach (self::NAMING_SURFACES as $surface) {
            foreach ($this->filesIn(self::ROOT.'/'.$surface) as $file) {
                $relative = $surface.'/'.$file->getFilename();

                // Boundaries matter more than they look. A case-insensitive
                // substring search reports WordPressSiteState, LicenceState and
                // every other *State class in the codebase, because "SitESTATE"
                // is in there if you stop reading at the letters. The name has
                // to start where a word or a camel-case segment starts.
                if (preg_match('/(?<![A-Za-z])estate|(?<![A-Z])Estate/', $file->getFilename()) === 1) {
                    $violations[] = "filename: {$relative}";
                }

                $source = (string) file_get_contents($file->getPathname());

                // A name, not the English word. Namespaces, class names,
                // translation keys and table names all take this shape;
                // "the estate" in a sentence does not.
                if (preg_match('/\\\\Estate\\\\|\bEstate[A-Z]|\bestate\.[a-z]|[\'"]estate_|\bclass Estate\b/', $source) === 1) {
                    $violations[] = "symbol or key: {$relative}";
                }
            }
        }

        $this->assertSame([], $violations, "'Estate' is becoming a permanent name again:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function the_concerns_that_exist_are_separate_modules(): void
    {
        /*
         * Only the modules that have code are asserted to exist. The first
         * version of this test also demanded ProductReadiness, which at the
         * time was three empty directories on one machine — so it passed
         * there and failed in CI, where git had never seen them. A gate
         * asserting that a module exists before it has any code is exactly
         * the claim-without-substance this phase is meant to catch, made by
         * the gate itself. ProductReadiness joins this list in the commit
         * that gives it something to own.
         */
        foreach (['Infrastructure', 'Providers'] as $module) {
            $this->assertDirectoryExists(
                self::ROOT.'/src/Modules/'.$module,
                "{$module} is one of the control centre's bounded concerns and is missing.",
            );
        }
    }

    #[Test]
    public function product_readiness_is_not_folded_into_another_concern(): void
    {
        // The rule that matters while the third module does not exist yet:
        // whoever builds it must not build it inside one of the other two.
        // "Whether the platform may sell a thing" is neither a machine nor an
        // account, and a Readiness directory under Providers is how a module
        // split becomes a directory naming convention.
        foreach (['Infrastructure', 'Providers'] as $module) {
            foreach (['ProductReadiness', 'Readiness', 'Products'] as $folded) {
                $this->assertDirectoryDoesNotExist(
                    self::ROOT.'/src/Modules/'.$module.'/'.$folded,
                    "Product readiness belongs in its own module, not under {$module}.",
                );
            }
        }
    }

    #[Test]
    public function control_center_is_a_navigation_idea_and_not_a_module(): void
    {
        foreach (['ControlCenter', 'ControlCentre'] as $forbidden) {
            $this->assertDirectoryDoesNotExist(
                self::ROOT.'/src/Modules/'.$forbidden,
                'Control Center is how the admin UI composes three concerns into one navigation area. '
                .'A module by that name would be the god object those three were split out of.',
            );
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
