<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Naming;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Infrastructure\Domain\Naming\DnsSuffix;
use Lynomia\Modules\Infrastructure\Domain\Naming\InfrastructureNamingPolicy;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingFinding;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingScope;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;

/**
 * What in this estate does not obey the naming standard.
 *
 * ===========================================================================
 * WHY THIS READS AND NEVER WRITES
 * ===========================================================================
 *
 * Because the alternative is a migration nobody asked for. These identifiers
 * are referenced by orders, audit entries, deployment plans and monitoring
 * series; a tool that "fixed" a rack code would break every one of those
 * references and the operator would learn about it from a graph that stopped.
 * So every check here produces a sentence and a next action, and the rename is
 * somebody's decision.
 *
 * ===========================================================================
 * WHAT A WARNING MEANS AND WHAT A FAILURE MEANS
 * ===========================================================================
 *
 * A value that does not match the standard is a WARNING. It was legal when it
 * was written, it still works, and the standard arrived afterwards — an
 * application that refused to boot over it would be a naming policy holding a
 * platform hostage.
 *
 * A FAILURE is something that is wrong now: two rows that normalise to one
 * identity, so the platform cannot say which one an operator means; a
 * production row carrying a name reserved for documents, which cannot resolve;
 * a configured production DNS suffix under one of those reserved domains,
 * which would make every composed name unresolvable at once.
 *
 * ===========================================================================
 * WHAT IT DOES NOT CHECK, AND WHERE THAT IS CHECKED INSTEAD
 * ===========================================================================
 *
 * Source literals. "Is a reference node id hardcoded in business logic" is a
 * question about the repository rather than about the estate, and it is
 * answered by the architecture tests that run on every push — which is a
 * stronger place for it than a command somebody has to remember to run. This
 * service is about the rows and the configuration a running deployment holds.
 */
final readonly class AuditInfrastructureNaming
{
    public function __construct(
        private InfrastructureNamingPolicy $policy = new InfrastructureNamingPolicy,
        private ReferenceValues $reference = new ReferenceValues,
    ) {}

    /**
     * @return list<NamingFinding>
     */
    public function execute(bool $production): array
    {
        return [
            ...$this->auditSuffix($production),
            ...$this->auditPersistedNames(),
            ...$this->auditCollisions(),
            ...$this->auditProductionRows($production),
            ...$this->auditMixedAuthorities(),
        ];
    }

    /**
     * The one piece of configuration this standard introduces.
     *
     * Unset is a pass with a sentence, not a warning: this platform has no real
     * estate yet, and a deployment that stores the hostnames it is given needs
     * no zone of its own. What is never allowed is a production suffix under a
     * domain reserved for examples — that composes names guaranteed not to
     * resolve, and it composes all of them at once.
     *
     * @return list<NamingFinding>
     */
    private function auditSuffix(bool $production): array
    {
        $configured = config(DnsSuffix::INTERNAL_CONFIG_KEY);

        if (! is_string($configured) || trim($configured) === '') {
            return [NamingFinding::pass(
                'naming.dns_suffix',
                'internal DNS suffix',
                'No internal zone is configured, so the platform stores the hostnames it is given and composes none.',
            )];
        }

        $problem = DnsSuffix::problemWith($configured, $production);

        if ($problem === null) {
            return [NamingFinding::pass(
                'naming.dns_suffix',
                'internal DNS suffix',
                sprintf('Hostnames compose under %s.', DnsName::canonical($configured)),
            )];
        }

        return [NamingFinding::fail(
            'naming.dns_suffix',
            'internal DNS suffix',
            sprintf('The configured internal zone cannot be used: %s.', $problem),
            sprintf(
                'Set %s to the zone this estate actually resolves under, or unset it and store fully qualified hostnames as the provider gives them.',
                'INFRASTRUCTURE_INTERNAL_DNS_SUFFIX',
            ),
        )];
    }

    /**
     * Every named row, against the rule for its kind.
     *
     * One loop over the concept catalogue rather than a check per table, so
     * that a concept added to {@see NamingConcept} is audited without anybody
     * remembering to extend this.
     *
     * @return list<NamingFinding>
     */
    private function auditPersistedNames(): array
    {
        $findings = [];

        foreach (NamingConcept::persisted() as $concept) {
            $column = $concept->column();
            $offenders = [];

            foreach ($this->values($concept) as $row) {
                $value = $row[$column] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $problem = $this->policy->problemWith($concept, $value);

                if ($problem !== null) {
                    $offenders[] = sprintf('%s (%s)', $value, $problem);
                }
            }

            if ($offenders === []) {
                continue;
            }

            $findings[] = NamingFinding::warn(
                'naming.noncanonical',
                $concept->value,
                sprintf(
                    '%d value(s) do not match the standard for a %s: %s.',
                    count($offenders),
                    $concept->kind()->label(),
                    implode('; ', array_slice($offenders, 0, 5)),
                ),
                $this->nextAction($concept),
                $concept,
            );
        }

        return $findings;
    }

    /**
     * What to do about values that do not match the standard.
     *
     * Different advice for a stable identity, because the answer is not
     * "rename it": orders, audit history and monitoring point at it, and the
     * rename is a migration somebody plans. The example is appended only when
     * the model can supply one — an invented example would be a naming
     * authority arriving through a help string.
     */
    private function nextAction(NamingConcept $concept): string
    {
        $action = $concept->kind()->isStableIdentity()
            ? sprintf(
                'Plan a migration for these: %s is referenced by orders, audit history and monitoring, so it is not renamed in place.',
                $concept->value,
            )
            : 'Rename these through the Admin surface that owns them.';

        $example = $this->policy->example($concept);

        return $example === null
            ? $action
            : sprintf('%s An example of a compliant value is "%s".', $action, $example);
    }

    /**
     * Two values that are one identity.
     *
     * The database's unique indexes compare literally, so `Node-01` and
     * `node-01` are two rows to PostgreSQL and one machine to everybody else.
     * This is the check that catches what the index cannot, and it is a
     * failure rather than a warning because nothing downstream can say which
     * of the two an operator meant.
     *
     * @return list<NamingFinding>
     */
    private function auditCollisions(): array
    {
        $findings = [];

        foreach (NamingConcept::persisted() as $concept) {
            if ($concept->scope() === NamingScope::NotUnique) {
                continue;
            }

            $column = $concept->column();
            $seen = [];

            foreach ($this->values($concept) as $row) {
                $value = $row[$column] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $scopeKey = [];

                foreach ($concept->scope()->keyColumns($column) as $keyColumn) {
                    if ($keyColumn === $column) {
                        continue;
                    }

                    $scopeKey[] = (string) ($row[$keyColumn] ?? '');
                }

                $key = implode('|', [...$scopeKey, $this->policy->collisionKey($concept, $value)]);

                if (isset($seen[$key])) {
                    $findings[] = NamingFinding::fail(
                        'naming.collision',
                        $concept->value,
                        sprintf(
                            '"%s" and "%s" are one identity %s: they differ only in spelling the platform does not distinguish.',
                            $seen[$key],
                            $value,
                            $concept->scope()->explanation(),
                        ),
                        sprintf('Decide which of the two is the real one and retire the other. Until then, anything addressing %s by name is ambiguous.', $concept->value),
                        $concept,
                    );

                    continue;
                }

                $seen[$key] = $value;
            }
        }

        return $findings;
    }

    /**
     * A row that says it is production, carrying a name that cannot be.
     *
     * The reference topology is a model and its names are reserved on purpose.
     * A production machine or provider holding one is either a copy-paste from
     * the model or a real row somebody never finished, and both are worth
     * stopping for. {@see ReferenceValues} decides what counts, so this and the
     * endpoint policy refuse the same set for the same reasons.
     *
     * @return list<NamingFinding>
     */
    private function auditProductionRows(bool $production): array
    {
        $findings = [];

        $rows = [
            'managed_servers' => ['name', 'management_address', 'bmc_address'],
            'provider_instances' => ['name', 'endpoint'],
        ];

        foreach ($rows as $table => $columns) {
            $records = DB::table($table)
                ->where('environment', DeploymentEnvironment::Production->value)
                ->get(['name', ...array_slice($columns, 1)]);

            foreach ($records as $record) {
                $fields = (array) $record;
                $name = (string) ($fields['name'] ?? '');

                foreach ($columns as $column) {
                    $value = $fields[$column] ?? null;

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $refusal = $this->reference->refuseForProduction($value);

                    if ($refusal === null) {
                        continue;
                    }

                    $findings[] = NamingFinding::fail(
                        'naming.reference_value_in_production',
                        sprintf('%s.%s (%s)', $table, $column, $name),
                        sprintf('A row declared for production carries a reference value: %s', $refusal),
                        'Either give this row the real value for the estate it names, or move it to a non-production environment.',
                    );
                }
            }
        }

        if ($findings === []) {
            $findings[] = NamingFinding::pass(
                'naming.reference_value_in_production',
                $production ? 'production rows' : 'rows declared for production',
                'No row declared for production carries a reference name, address or endpoint.',
            );
        }

        return $findings;
    }

    /**
     * Hostnames that answer to a different naming authority than the configured
     * one.
     *
     * Only meaningful once a zone is configured: before that there is nothing
     * to disagree with. After it, a hosting node still named under some other
     * zone is either a machine that has not been migrated or a second scheme
     * nobody retired, and either way somebody should know which.
     *
     * @return list<NamingFinding>
     */
    private function auditMixedAuthorities(): array
    {
        $suffix = $this->policy->internalDnsSuffix(production: false);

        if ($suffix === null) {
            return [];
        }

        $outside = [];

        foreach (DB::table('hosting_nodes')->get(['slug', 'hostname']) as $node) {
            $hostname = (string) ($node->hostname ?? '');

            if ($hostname === '') {
                continue;
            }

            $name = DnsName::tryFrom($hostname);

            // A bare label is not a disagreement: it has no zone at all, and
            // composing one is exactly what the configured suffix is for.
            if ($name === null || ! $name->isFullyQualified() || $suffix->covers($name)) {
                continue;
            }

            $outside[] = sprintf('%s (%s)', (string) $node->slug, $name->value());
        }

        if ($outside === []) {
            return [NamingFinding::pass(
                'naming.one_authority',
                'hostnames',
                sprintf('Every qualified hostname is under the configured zone %s.', $suffix->value()),
            )];
        }

        return [NamingFinding::warn(
            'naming.one_authority',
            'hostnames',
            sprintf(
                '%d hostname(s) are qualified under a zone other than the configured %s: %s.',
                count($outside),
                $suffix->value(),
                implode('; ', array_slice($outside, 0, 5)),
            ),
            'Decide which zone is authoritative. Two live naming schemes means every name has to be checked against both before anybody trusts it.',
            NamingConcept::HostingNodeHostname,
        )];
    }

    /**
     * The rows of one concept, with the columns its scope is judged across.
     *
     * @return list<array<string, mixed>>
     */
    private function values(NamingConcept $concept): array
    {
        $column = $concept->column();
        $columns = $concept->scope()->keyColumns($column);

        if (! in_array($column, $columns, strict: true)) {
            $columns[] = $column;
        }

        return DB::table($concept->table())
            ->get($columns)
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
