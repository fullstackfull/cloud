<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every field the portal reads is a field the API description declares.
 *
 * W5.8 found a defect that nothing in the repository could have caught. The
 * token endpoint answers `{"data": {..., "token": "..."}}`, `docs/openapi.yaml`
 * had said `token` all along, and the portal read `plain_text_token`. So a
 * customer creating an API token saw an empty box under the sentence telling
 * them it is the only time the value is shown — and the platform keeps only a
 * hash. TypeScript could not catch it because the response type was written by
 * hand: a hand-written type is a claim about the server, and the compiler
 * checks it against nothing.
 *
 * W5.9 §9 asks for a bounded gate against that class of defect and explicitly
 * not for a code-generation migration. This is the bounded gate.
 *
 * ## What it compares, and in which direction
 *
 * The portal's response types live in `apps/web/src/lib/types.ts`, and the
 * description's response schemas live in `components.schemas`. They are named
 * the same — `Invoice`, `ApiToken`, `Domain`, `Ticket` — so the pairing is
 * automatic rather than a list somebody maintains. Every property of a paired
 * TypeScript interface must be declared in the schema of the same name.
 *
 * One direction only. A client that reads fewer fields than the API returns is
 * fine and normal — a list screen has no use for twelve addresses. A client
 * that reads a field the API does not declare is the `plain_text_token` bug,
 * and it is silent: the value arrives `undefined`, React renders nothing, and
 * the screen looks like it worked.
 *
 * The schemas are strict — `additionalProperties: false` — so a property
 * absent from `properties` really is absent from the contract rather than
 * merely undocumented.
 *
 * ## Why this lives in the backend suite
 *
 * Because the parser it needs is here. Reading 22,000 lines of YAML wants a
 * real YAML parser, `symfony/yaml` is already a dependency of the framework,
 * and the alternative was adding one to the web app in a closure wave to read
 * a file the web app does not ship. The contract has two sides and a test of
 * it has to touch both; this one reads the portal's types as text, which is
 * the simpler of the two grammars.
 *
 * ## What it does not do
 *
 * It does not check types, only names. `id: number` against a string schema
 * would pass here. Names are where the defect was, names are what silently
 * produce `undefined`, and a type checker over a generated client is the
 * proper answer to the rest — recorded as a future architecture item rather
 * than attempted in a closure wave.
 */
final class TheClientAndTheDescriptionAgreeTest extends TestCase
{
    /**
     * The contracts §9 names, where a wrong key costs a credential, money, a
     * destructive action, a resource's state, or a security decision.
     *
     * Asserted to be among the compared pairs, so the automatic pairing cannot
     * quietly stop covering the ones that matter.
     *
     * @var list<string>
     */
    private const CRITICAL = [
        'IssuedApiToken',   // the credential shown once — the W5.8 defect
        'ApiToken',
        'AuthenticatedUser', // login and session identity
        'Invoice',
        'Payment',
        'WalletTransaction',
        'CustomerOperation', // a 202 read back as a state
        'ActivityItem',
        'AccountOverview',   // the dashboard
        'Service',
        'VirtualMachine',
        'DedicatedServer',
        'Domain',
        'DnsRecord',
        'Ticket',
    ];

    #[Test]
    public function no_field_the_portal_reads_is_missing_from_the_api_description(): void
    {
        $schemas = $this->describedSchemas();
        $interfaces = $this->portalInterfaces();

        $paired = array_keys(array_intersect_key($interfaces, $schemas));

        $this->assertGreaterThan(
            40,
            count($paired),
            'Barely anything paired up, which usually means the interface parser stopped matching rather '
            .'than that the portal stopped having types.'
        );

        $drift = [];

        foreach ($paired as $name) {
            $declared = $schemas[$name];

            foreach ($interfaces[$name] as $property) {
                if (! in_array($property, $declared, strict: true)) {
                    $drift[] = sprintf(
                        '%-22s reads .%-26s which #/components/schemas/%s does not declare',
                        $name,
                        $property,
                        $name,
                    );
                }
            }
        }

        $this->assertSame([], $drift, sprintf(
            "%d field(s) the portal reads are not in the API description:\n\n%s\n\n".
            'Each is a value that arrives undefined and renders as nothing, which is how a customer was '.
            'shown an empty box where their only copy of an API token should have been. Either the '.
            'description is missing a field the API really returns, or the portal is reading a name the '.
            "API never had.\n",
            count($drift),
            implode("\n", $drift),
        ));
    }

    #[Test]
    public function the_contracts_that_can_cost_something_are_among_the_compared_pairs(): void
    {
        $schemas = $this->describedSchemas();
        $interfaces = $this->portalInterfaces();

        $uncompared = [];

        foreach (self::CRITICAL as $name) {
            $schemaName = self::ALIASES[$name] ?? $name;

            $reason = match (true) {
                ! array_key_exists($name, $interfaces) => 'the portal has no interface of this name',
                ! array_key_exists($schemaName, $schemas) => "the description has no schema called {$schemaName}",
                default => null,
            };

            if ($reason !== null) {
                $uncompared[] = sprintf('%-22s %s', $name, $reason);
            }
        }

        $this->assertSame([], $uncompared, sprintf(
            'These contracts are the ones §9 calls critical — a wrong key costs a credential, money, a '.
            "destructive action, a resource's state or a security decision — and they are not being ".
            "compared:\n\n%s\n\n".
            'A rename on either side puts a contract outside the gate while leaving the gate green, '.
            "which is the failure this test exists to prevent.\n",
            implode("\n", $uncompared),
        ));
    }

    /**
     * The described response schemas, by name, as lists of property names.
     *
     * ## Scanned rather than parsed, and why that is safe here
     *
     * There is no YAML parser in this application and none in the portal, and
     * adding one to answer a question this size — what property names does one
     * named schema declare — would be a dependency added during a closure
     * wave, in both lockfiles, for a file neither side ships.
     *
     * So this reads the indentation. `docs/openapi.yaml` is generated by the
     * platform's own exporter and validated by its own CI job, so its shape is
     * fixed: schema names sit at four spaces under `components.schemas`,
     * `properties:` at six, and each property name at eight. This is not a
     * YAML parser and must not be mistaken for one; it answers one question
     * about one known layout.
     *
     * The danger with a scanner is not that it breaks loudly but that it
     * stops matching and quietly reports nothing, which would turn this gate
     * green forever. Two things prevent that: the caller requires a minimum
     * number of paired schemas, and {@see self::the_scan_still_reads_the_description}
     * checks known properties of known schemas, so a scan that degrades fails
     * by name.
     *
     * @return array<string, list<string>>
     */
    private function describedSchemas(): array
    {
        $path = base_path('../../docs/openapi.yaml');

        $this->assertFileExists($path, 'The API description is the other half of this comparison.');

        $lines = explode("\n", (string) file_get_contents($path));

        /** @var array<string, list<string>> $schemas */
        $schemas = [];
        /** @var array<string, string> $envelopes */
        $envelopes = [];

        $inSchemas = false;
        $schema = null;
        $inProperties = false;
        $pendingDataRef = false;

        foreach ($lines as $line) {
            if (rtrim($line) === '  schemas:') {
                $inSchemas = true;

                continue;
            }

            if (! $inSchemas) {
                continue;
            }

            // A new top-level key ends the schemas block.
            if ($line !== '' && $line[0] !== ' ' && trim($line) !== '') {
                break;
            }

            if (preg_match('/^    "?([A-Za-z0-9_]+)"?:\s*$/', $line, $m) === 1) {
                $schema = $m[1];
                $schemas[$schema] ??= [];
                $inProperties = false;
                $pendingDataRef = false;

                continue;
            }

            if ($schema === null) {
                continue;
            }

            if (rtrim($line) === '      properties:') {
                $inProperties = true;

                continue;
            }

            // Any other six-space key ends this schema's property list.
            if (preg_match('/^      "?[A-Za-z0-9_$]+"?:/', $line) === 1) {
                $inProperties = false;

                continue;
            }

            if ($pendingDataRef && preg_match('/"\$ref":\s*"#\/components\/schemas\/([A-Za-z0-9_]+)"/', $line, $m) === 1) {
                $envelopes[$schema] = $m[1];
                $pendingDataRef = false;

                continue;
            }

            if ($inProperties && preg_match('/^        "?([A-Za-z0-9_]+)"?:\s*$/', $line, $m) === 1) {
                $schemas[$schema][] = $m[1];
                $pendingDataRef = $m[1] === 'data';

                continue;
            }
        }

        $this->assertNotEmpty($schemas, 'The scan read no schemas at all from the description.');

        /*
         * `{data: Thing}` is an envelope rather than a contract of its own, so
         * `InvoiceResponse` is compared against what `Invoice` declares. Done
         * after the scan because a schema may be referenced before it appears.
         */
        foreach ($envelopes as $envelope => $inner) {
            if ($schemas[$envelope] === ['data'] && isset($schemas[$inner])) {
                $schemas[$envelope] = $schemas[$inner];
            }
        }

        return array_filter($schemas, static fn (array $properties): bool => $properties !== []);
    }

    /**
     * The scan still reads what it is supposed to read.
     *
     * Known properties of known schemas, chosen because they are unremarkable
     * and therefore unlikely to be removed for a reason this test should not
     * outlive. If the description's formatting ever changes under the scanner,
     * this fails by name rather than letting the gate above pass on an empty
     * reading.
     */
    #[Test]
    public function the_scan_still_reads_the_description(): void
    {
        $schemas = $this->describedSchemas();

        $this->assertGreaterThan(200, count($schemas), 'The scan found far fewer schemas than the description declares.');

        foreach ([
            'ApiToken' => ['id', 'name', 'revoked_reason', 'last_used_ip'],
            'Invoice' => ['id', 'number', 'status', 'currency'],
            'IssuedApiToken' => ['token'],
        ] as $schema => $expected) {
            $this->assertArrayHasKey($schema, $schemas, "The scan did not find the {$schema} schema.");

            foreach ($expected as $property) {
                $this->assertContains(
                    $property,
                    $schemas[$schema],
                    "The scan did not find {$schema}.{$property}, which the description declares.",
                );
            }
        }
    }

    /**
     * Portal interfaces whose name differs from the schema's.
     *
     * Kept deliberately short. Auto-pairing by name is what makes this gate
     * maintain itself, and every entry here is a pair that no longer maintains
     * itself — so each one names why it exists rather than simply existing.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        // The session identity. The portal calls it what it is from its side —
        // the person who is signed in — and the API calls it the resource it
        // serialises. Both readings are right and neither is going to move.
        'AuthenticatedUser' => 'User',
    ];

    /**
     * The portal's response interfaces, by name, as lists of property names.
     *
     * Parsed as text rather than compiled. The file is one property per line
     * in a consistent house style — no semicolons, no inline object literals
     * in these types — so a line scanner at brace depth one answers the only
     * question being asked, which is what names does the portal read. A
     * TypeScript compiler host to answer that would be a dependency and a
     * build step for a question this size.
     *
     * `extends` is followed, because `IssuedApiToken extends ApiToken` is
     * exactly the shape the W5.8 defect lived in.
     *
     * @return array<string, list<string>>
     */
    private function portalInterfaces(): array
    {
        /*
         * Two files. Most response types live in `lib/types.ts`; the session
         * identity lives beside the hook that fetches it, which is a
         * reasonable place for it and would otherwise put the login contract —
         * one of the ones §9 calls critical — outside this gate entirely.
         */
        $lines = [];

        foreach (['../web/src/lib/types.ts', '../web/src/features/auth/useAuth.ts'] as $relative) {
            $path = base_path($relative);

            $this->assertFileExists($path, "The portal types at {$relative} are the other half of this comparison.");

            $lines = [...$lines, ...explode("\n", (string) file_get_contents($path))];
        }

        $interfaces = [];
        $extends = [];

        $current = null;
        $depth = 0;
        $inBlockComment = false;

        foreach ($lines as $line) {
            if ($inBlockComment) {
                $inBlockComment = ! str_contains($line, '*/');

                continue;
            }

            $trimmed = trim($line);

            if (str_starts_with($trimmed, '/*')) {
                $inBlockComment = ! str_contains($trimmed, '*/');

                continue;
            }

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            if ($current === null) {
                if (preg_match('/^export interface ([A-Za-z0-9_]+)(?:<[^>]*>)?(?:\s+extends\s+([A-Za-z0-9_,\s]+?))?\s*\{/', $trimmed, $m) === 1) {
                    $current = $m[1];
                    $depth = 1;
                    $interfaces[$current] = [];

                    if (($m[2] ?? '') !== '') {
                        $extends[$current] = array_map('trim', explode(',', $m[2]));
                    }
                }

                continue;
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            if ($depth <= 0) {
                $current = null;

                continue;
            }

            // Depth one only: a property of this interface rather than a
            // property of an object nested inside one.
            if ($depth === 1 && preg_match('/^([a-z_][A-Za-z0-9_]*)\??\s*:/', $trimmed, $m) === 1) {
                $interfaces[$current][] = $m[1];
            }
        }

        // Inherited properties, one level of resolution being enough for this
        // file and asserted rather than assumed by the count check above.
        foreach ($extends as $child => $parents) {
            foreach ($parents as $parent) {
                foreach ($interfaces[$parent] ?? [] as $property) {
                    if (! in_array($property, $interfaces[$child], strict: true)) {
                        $interfaces[$child][] = $property;
                    }
                }
            }
        }

        // And the handful whose names differ, under the schema's name.
        foreach (self::ALIASES as $portal => $schema) {
            if (isset($interfaces[$portal])) {
                $interfaces[$schema] = $interfaces[$portal];
            }
        }

        return $interfaces;
    }
}
