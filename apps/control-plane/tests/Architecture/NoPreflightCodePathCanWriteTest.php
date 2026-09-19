<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * A preflight observes. This is what makes that true.
 *
 * ===========================================================================
 * WHY A TEST AND NOT A REVIEW HABIT
 * ===========================================================================
 *
 * Because the write that breaks a preflight is not a reckless one. It is the
 * obvious, helpful line somebody adds in six months: "while we're here, record
 * the connection state so the screen is up to date", or "mark the credential
 * invalid since we just found out". Both are reasonable things to want. Both
 * turn a diagnostic into an actor, and both change the answer to the question
 * the operator asked — a preflight that marks a credential Invalid while
 * explaining why a product cannot be sold has altered the thing it was
 * describing.
 *
 * So the rule is asserted three ways, because they fail differently:
 *
 *   1. **Nothing in the preflight namespace writes.** A source scan for the
 *      write verbs Eloquent and the query builder expose.
 *   2. **Nothing that writes is injected.** A reflection pass over the
 *      service's own constructor: the recording action is not a dependency, so
 *      no check can reach it however it is written.
 *   3. **The readiness engine is consulted, never recorded.** The one injected
 *      collaborator that *can* persist is asked only for its pure verdict, and
 *      the method it is asked for is named here.
 *
 * The deliberate breakage in the Gap 3 suite adds a write to the orchestrator
 * and proves this file fails.
 */
final class NoPreflightCodePathCanWriteTest extends TestCase
{
    /**
     * The directories that make up the preflight engine.
     *
     * The HTTP controller is deliberately not here: it writes exactly one
     * thing, the audit entry recording that a run happened, and
     * {@see self::the_only_write_the_preflight_endpoint_performs_is_its_own_audit_entry}
     * pins that down separately. The engine itself writes nothing at all.
     *
     * @var list<string>
     */
    private const array ENGINE = [
        'src/Modules/Infrastructure/Application/Preflight',
        'src/Modules/Infrastructure/Domain/Preflight',
    ];

    /**
     * Every way this codebase changes something, as it appears in source.
     *
     * Eloquent's persistence verbs, the query builder's, the job dispatchers,
     * and the transaction wrapper — because a preflight that opened a
     * transaction would be a preflight that expected to write.
     *
     * @var array<string, string>
     */
    private const array WRITES = [
        '->save(' => 'Eloquent persistence',
        '->delete(' => 'Eloquent deletion',
        '->forceDelete(' => 'Eloquent deletion',
        '->restore(' => 'Eloquent restoration',
        '->forceFill(' => 'a mass assignment, which is only ever followed by a save',
        '->update(' => 'a bulk update',
        '->updateOrCreate(' => 'an upsert',
        '->firstOrCreate(' => 'an upsert',
        '->createOrFirst(' => 'an upsert',
        '->increment(' => 'an in-place increment',
        '->decrement(' => 'an in-place decrement',
        '->insert(' => 'a raw insert',
        '->upsert(' => 'a raw upsert',
        '->truncate(' => 'a truncation',
        'DB::insert(' => 'a raw insert',
        'DB::update(' => 'a raw update',
        'DB::delete(' => 'a raw delete',
        'DB::statement(' => 'a raw statement',
        'DB::transaction(' => 'a transaction, which a read does not need',
        'DB::beginTransaction(' => 'a transaction, which a read does not need',
        '::dispatch(' => 'a queued job, which will write wherever it runs',
        'dispatch_sync(' => 'a job run inline',
    ];

    #[Test]
    public function no_file_in_the_preflight_engine_contains_a_write(): void
    {
        $offences = [];

        foreach ($this->engineFiles() as $path => $source) {
            foreach (self::WRITES as $needle => $what) {
                if (str_contains($source, $needle)) {
                    $offences[] = sprintf('%s contains "%s" — %s.', $path, $needle, $what);
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "The preflight engine writes:\n  ".implode("\n  ", $offences)
            ."\n\nA preflight observes. The most likely reason this is failing is a helpful line that records what "
            .'the preflight just found out — which changes the state of the thing it was asked to diagnose, and so '
            .'changes the answer. Record it from the action that already does that, not from here.',
        );
    }

    #[Test]
    public function the_scanner_is_actually_looking_at_something(): void
    {
        /*
         * The gate on the gate. A scanner that found no files would pass the
         * assertion above for the emptiest possible reason, and would go on
         * passing after somebody moved the directory.
         */
        $files = $this->engineFiles();

        $this->assertGreaterThanOrEqual(
            8,
            count($files),
            'The preflight engine scan found almost no files. Either the engine moved, or this scanner is broken — '
            .'and a broken scanner passes every assertion in this file for the wrong reason.',
        );

        $this->assertArrayHasKey(
            'src/Modules/Infrastructure/Application/Preflight/InfrastructurePreflightService.php',
            $files,
            'The orchestrator is not in the scanned set, which is the one file that most needs to be.',
        );
    }

    #[Test]
    public function nothing_that_records_a_connection_test_is_reachable_from_the_orchestrator(): void
    {
        /*
         * The structural half, and the reason the read-only probe was
         * extracted from the connection test in the first place.
         *
         * TestConnection writes five places — the provider row, the capability
         * rows, the credential's state, a connection_tests record and an audit
         * entry — all of which is right for an operator pressing a button and
         * wrong for a diagnosis. It is not injected here, so no amount of
         * creative writing inside a check can reach it.
         */
        $constructor = (new ReflectionClass(InfrastructurePreflightService::class))->getConstructor();

        $this->assertNotNull($constructor);

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $dependencies[] = $type->getName();
            }
        }

        $this->assertNotContains(
            TestConnection::class,
            $dependencies,
            'The preflight orchestrator is given the action that records a connection test. That action writes the '
            .'provider row, the capability rows, the credential state, a connection_tests record and an audit entry. '
            .'Use ProbeProvider, which is the read-only half and exists for exactly this.',
        );

        foreach ($dependencies as $dependency) {
            $this->assertStringNotContainsString(
                'Jobs\\',
                $dependency,
                sprintf('The orchestrator is given %s, which is a job. A preflight dispatches nothing.', $dependency),
            );
        }
    }

    #[Test]
    public function the_readiness_engine_is_consulted_and_never_recorded(): void
    {
        /*
         * AssessProduct is the one injected collaborator that can persist —
         * `execute()` writes a readiness row and can withdraw a sellability
         * declaration, audited. Preflight asks it for `verdictFor`, which is
         * the same computation with the recording left off.
         *
         * This is pinned by name rather than left to reading, because the two
         * methods differ by one word at the call site and by a database write
         * in effect.
         */
        $source = $this->engineFiles()['src/Modules/Infrastructure/Application/Preflight/InfrastructurePreflightService.php'];

        preg_match_all('/\$this->readiness->(\w+)\(/', $source, $calls);

        $this->assertNotSame([], $calls[1], 'The orchestrator no longer consults the readiness engine at all. If readiness moved, move this assertion with it rather than deleting it.');

        $this->assertSame(
            ['verdictFor'],
            array_values(array_unique($calls[1])),
            'The preflight calls something other than verdictFor on the readiness engine. `execute()` persists a '
            .'readiness row and can withdraw a product\'s sellability; a preflight must not move the state of the '
            .'thing it was asked to diagnose.',
        );
    }

    #[Test]
    public function the_only_write_the_preflight_endpoint_performs_is_its_own_audit_entry(): void
    {
        /*
         * The controller is allowed one write and it is the run itself: who
         * asked, in which mode, about what, and what came back. A preflight
         * leaves no other trace, which is exactly why that trace is worth
         * keeping.
         */
        $path = __DIR__.'/../../src/Modules/Infrastructure/Http/Controllers/PreflightController.php';

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        foreach (self::WRITES as $needle => $what) {
            if ($needle === 'DB::transaction(') {
                // RecordActAtomically opens one on the controller's behalf,
                // which is its whole purpose: the act and its record share a
                // transaction. The controller does not open one itself.
                continue;
            }

            $this->assertStringNotContainsString(
                $needle,
                $source,
                sprintf('The preflight endpoint contains "%s" — %s. Its only write is the audit entry.', $needle, $what),
            );
        }

        $this->assertStringContainsString(
            'AuditAction::InfrastructurePreflightRun',
            $source,
            'The preflight endpoint does not record that a run happened. A run that leaves no trace at all is a run '
            .'nobody can account for afterwards.',
        );
    }

    /**
     * @return array<string, string> repository-relative path => source
     */
    private function engineFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (self::ENGINE as $directory) {
            $absolute = $root.'/'.$directory;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[$directory.'/'.$file->getFilename()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
