<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * When the real datacenter arrives, nobody edits code.
 *
 * ===========================================================================
 * THE CLAIM THIS GATE MAKES CHECKABLE
 * ===========================================================================
 *
 * The reference estate names a region, a site, a rack, three nodes, five
 * storage pools, four networks, four templates, four machines, five chassis,
 * a hosting node and two providers. Every one of those names is fictional. If
 * any of them is load-bearing — if some scheduler reaches for
 * `ref-node-alpha-1-a`, or a readiness rule tests for `ref-cluster-alpha-1` —
 * then supplying real values means editing business logic, and Gap 4's whole
 * purpose is gone.
 *
 * That is not a claim a reader can verify by being careful. It is one grep,
 * and the `ref-` prefix on every logical id exists so that the grep is
 * decidable: one pattern finds all of them, including any that a future
 * contributor adds.
 *
 * ===========================================================================
 * WHERE REFERENCE LITERALS ARE CORRECT
 * ===========================================================================
 *
 * In the definition, in tests and fixtures, in documentation, and in the
 * simulation loader and the seeder that put the estate into a development
 * database. Those are the four places §35 permits and they are the four
 * directories excluded below. Application source — `src/`, `app/` and the
 * frontend — is where a reference literal would be a bug, and the loader is
 * deliberately NOT excluded from that rule by accident: it contains no
 * reference literal either, because it reads the ids out of the file rather
 * than naming them.
 *
 * Comments are stripped before searching, and that is a decision rather than
 * a convenience. A docblock reading `e.g. "ip=192.0.2.10/24,gw=192.0.2.1"` is
 * documentation — it is how CloudInitConfig explains Proxmox's ipconfig syntax,
 * and it is exactly the kind of example an RFC documentation range exists for.
 * The first version of this gate scanned raw file contents and flagged seven
 * such comments across the codebase; it was measuring prose. What matters is
 * whether a reference value is in the executable half.
 *
 * Addresses are matched with boundaries for the same reason: `192.0.2.1` is a
 * substring of `192.0.2.10`, and a gate that cannot tell a gateway from a host
 * would keep failing on things that are not the bug it is looking for.
 */
final class NoReferenceIdentifierIsRequiredByBusinessLogicTest extends TestCase
{
    /**
     * Directories a reference literal may appear in.
     *
     * @var list<string>
     */
    private const array PERMITTED = [
        'resources/reference-topology',
        'tests',
        'database/seeders',
        'docs',
    ];

    #[Test]
    public function no_reference_logical_id_appears_in_application_source(): void
    {
        $ids = ReferenceTopology::load()->ids();

        self::assertNotEmpty($ids, 'The reference topology declares no objects, so this gate would pass vacuously.');

        $offences = [];

        foreach ($this->applicationFiles() as $file) {
            $code = $this->codeOnly($file->getRealPath());

            foreach ($ids as $id) {
                if (str_contains($code, $id)) {
                    $offences[] = sprintf('%s names %s', $this->relative($file->getRealPath()), $id);
                }
            }
        }

        self::assertSame([], $offences, implode("\n", [
            'A reference topology identifier is named in application source.',
            'Reference names are fictional. Anything that requires one requires a source edit when real',
            'values arrive, which is the single thing this phase exists to prevent. Read the name out of',
            'the topology, or take it as configuration.',
            ...$offences,
        ]));
    }

    /**
     * The loader reads ids; it does not know them.
     *
     * A separate assertion because the loader is the one file whose job is to
     * turn the definition into rows, and it is therefore the most likely place
     * for somebody to shortcut by writing a name. The gate above already
     * covers it; this one says why it matters, and fails with the reason.
     */
    #[Test]
    public function the_simulation_loader_names_kinds_rather_than_objects(): void
    {
        $source = (string) file_get_contents(
            base_path('src/Modules/Infrastructure/Application/Reference/LoadReferenceTopologyForSimulation.php')
        );

        foreach (ReferenceTopology::load()->ids() as $id) {
            self::assertStringNotContainsString($id, $source, sprintf(
                'The loader names %s. It must ask the topology for objects of a kind and read their facts, so that renaming one object is a change to one file.',
                $id,
            ));
        }

        // The positive twin: it does reference the kinds, which is how it knows
        // what to write. A loader naming neither would pass the negative above
        // by doing nothing at all.
        foreach ([ReferenceKind::Node, ReferenceKind::Storage, ReferenceKind::Provider] as $kind) {
            self::assertStringContainsString(
                'ReferenceKind::'.ucfirst((string) preg_replace_callback('/_(\w)/', static fn (array $m): string => strtoupper($m[1]), $kind->value)),
                $source,
                sprintf('The loader never asks for %s objects, so that kind is declared and never written.', $kind->value),
            );
        }
    }

    /**
     * The estate's own hostnames and addresses are not named in source either.
     *
     * An independent check: somebody could avoid a logical id and still
     * hardcode a reference gateway as a default or a reference host as a
     * fallback. That is the same bug wearing a different string.
     */
    #[Test]
    public function no_reference_address_or_hostname_appears_in_application_source(): void
    {
        $topology = ReferenceTopology::load();
        $values = [];

        foreach ($topology->objects as $object) {
            foreach (['hostname', 'address', 'management_address', 'bmc_address', 'gateway', 'cidr'] as $field) {
                $value = $object->facts[$field] ?? null;

                if (is_string($value) && $value !== '') {
                    $values[$value] = true;
                }
            }
        }

        self::assertNotEmpty($values, 'The reference topology carries no addresses, so this gate would pass vacuously.');

        $offences = [];

        foreach ($this->applicationFiles() as $file) {
            $code = $this->codeOnly($file->getRealPath());

            foreach (array_keys($values) as $value) {
                // Bounded on both sides, so that the gateway 192.0.2.1 is not
                // reported because a host 192.0.2.10 is present.
                if (preg_match('/(?<![\w.:-])'.preg_quote($value, '/').'(?![\w.:-])/', $code) === 1) {
                    $offences[] = sprintf('%s names %s', $this->relative($file->getRealPath()), $value);
                }
            }
        }

        self::assertSame([], $offences, implode("\n", [
            'A reference address or hostname is named in application source.',
            ...$offences,
        ]));
    }

    /**
     * A file's executable half, with every comment removed.
     *
     * PHP is tokenised rather than regexed, because a `//` inside a string is
     * not a comment and a gate that thought it was would silently stop reading
     * the rest of the line. TypeScript has no tokeniser to hand here, so its
     * comments are stripped with a pattern that steps over quoted strings
     * first — the frontend holds no addresses today and the pattern is there so
     * that the answer does not depend on that staying true.
     */
    private function codeOnly(string $path): string
    {
        $contents = (string) file_get_contents($path);

        if (! str_ends_with($path, '.php')) {
            return (string) preg_replace(
                ['/(["\'`])(?:\\\\.|(?!\1).)*\1/s', '#/\*.*?\*/#s', '#//[^\n]*#'],
                ['""', '', ''],
                $contents,
            );
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function applicationFiles(): iterable
    {
        $finder = (new Finder)
            ->files()
            ->name(['*.php', '*.ts', '*.tsx'])
            ->in([base_path('src'), base_path('app'), base_path('../web/src')]);

        foreach (self::PERMITTED as $permitted) {
            $finder->notPath($permitted);
        }

        return $finder;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
