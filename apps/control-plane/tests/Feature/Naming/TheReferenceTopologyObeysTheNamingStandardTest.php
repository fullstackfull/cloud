<?php

declare(strict_types=1);

namespace Tests\Feature\Naming;

use Lynomia\Modules\Infrastructure\Domain\Naming\DnsSuffix;
use Lynomia\Modules\Infrastructure\Domain\Naming\InfrastructureNamingPolicy;
use Lynomia\Modules\Infrastructure\Domain\Naming\NameKind;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceObject;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\Shared\Domain\Naming\LogicalName;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reference topology as the standard's first consumer.
 *
 * ===========================================================================
 * WHY THE MODEL IS HELD TO THE STANDARD
 * ===========================================================================
 *
 * Because it is the thing everybody copies. It is the estate the seeders load,
 * the tests read, the documentation quotes and an operator onboarding a real
 * cluster works from. If its identifiers did not obey the naming standard, the
 * standard would be advice and the topology would be the real scheme — which
 * is exactly the two-authority problem Gap 5 exists to end, with the two
 * authorities inside one repository.
 *
 * So every logical id in resources/reference-topology/topology.php is checked
 * against the rule for its kind, and every hostname against the hostname rule.
 *
 * ===========================================================================
 * AND WHY ITS NAMES STAY IMPOSSIBLE IN PRODUCTION
 * ===========================================================================
 *
 * The other half of the same test. A model of an estate has to be
 * unmistakably a model: its names are under domains reserved for documents,
 * which means a production deployment that inherited one would be refused
 * rather than silently pointed at nothing. That refusal is asserted here, on
 * the topology's own values, so that "the reference estate cannot become
 * production" is a property of these strings rather than a promise in a
 * comment.
 */
final class TheReferenceTopologyObeysTheNamingStandardTest extends TestCase
{
    private ReferenceTopology $topology;

    private InfrastructureNamingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->topology = ReferenceTopology::load();
        $this->policy = new InfrastructureNamingPolicy;
    }

    #[Test]
    public function every_logical_id_in_the_model_is_a_valid_logical_identifier(): void
    {
        $ids = $this->topology->ids();

        $this->assertNotSame([], $ids, 'An empty topology would pass every assertion below vacuously.');

        foreach ($ids as $id) {
            $this->assertNull(
                LogicalName::problemWith($id, 255),
                sprintf('The reference id "%s" does not obey the naming standard it is the example of.', $id),
            );
        }
    }

    #[Test]
    public function every_id_is_also_written_the_way_the_reference_scheme_says(): void
    {
        // Two rules, not one. The first (above) is the platform's logical-name
        // syntax, which a real estate's ids obey too. This one is the reference
        // scheme's own prefix, which is what makes a value recognisable as
        // belonging to a model — and what the production guards match on.
        $reference = new ReferenceValues;

        foreach ($this->topology->ids() as $id) {
            $this->assertTrue(
                $reference->isCanonicalReferenceIdentifier($id),
                sprintf('The reference id "%s" is not written the way the reference scheme says.', $id),
            );
        }
    }

    #[Test]
    public function every_hostname_in_the_model_is_a_hostname(): void
    {
        $checked = 0;

        foreach ($this->hostnameFacts() as $context => $hostname) {
            $checked++;

            $this->assertNull(
                $this->policy->problemWith(NamingConcept::HostingNodeHostname, $hostname),
                sprintf('%s carries "%s", which is not a valid hostname.', $context, $hostname),
            );
        }

        $this->assertGreaterThan(0, $checked, 'The model declares no hostnames, so this gate would pass vacuously.');
    }

    #[Test]
    public function every_hostname_in_the_model_is_refused_as_a_production_zone(): void
    {
        $reference = new ReferenceValues;
        $checked = 0;

        foreach ($this->hostnameFacts() as $context => $hostname) {
            $name = DnsName::tryFrom($hostname);

            $this->assertNotNull($name);

            // Only qualified names carry a zone. A bare label has none to
            // judge, and the model does not use them today; the counter below
            // proves the test is looking at something either way.
            if (! $name->isFullyQualified()) {
                continue;
            }

            $checked++;

            $this->assertTrue(
                $reference->isDocumentationHostname($name->value()),
                sprintf('%s carries "%s", which is not under a domain reserved for examples — a production deployment could inherit it.', $context, $hostname),
            );

            $this->assertNotNull(
                DnsSuffix::problemWith($name->value(), production: true),
                sprintf('%s carries "%s", which would be accepted as a production DNS suffix.', $context, $hostname),
            );
        }

        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function the_model_still_says_it_is_not_production(): void
    {
        // Gap 4's machine-readable marker, re-asserted here because this gap
        // touched the topology's consumers: a naming standard that made the
        // model look deployable would have undone the previous gap.
        $this->assertFalse($this->topology->isProduction());
        $this->assertFalse($this->topology->isDeployable());
        $this->assertFalse($this->topology->isReachable());
    }

    #[Test]
    public function the_hostname_example_the_policy_publishes_is_the_models_own_hostname(): void
    {
        // Otherwise the generator is a third naming authority hiding in a
        // helper method: a hostname nobody declared, under a zone nobody
        // chose, offered to every form and document that asks for an example.
        $hostnames = array_values(iterator_to_array($this->hostnameFacts(), preserve_keys: false));

        $this->assertContains($this->policy->example(NamingConcept::HostingNodeHostname), $hostnames);
    }

    #[Test]
    public function the_examples_the_policy_publishes_come_from_the_model(): void
    {
        /*
         * The generator exists so that a form, a document or a test never
         * invents its own example. That is only true if the examples are the
         * model's own values — otherwise the policy is a third naming
         * authority, quietly, in a helper method.
         */
        $ids = $this->topology->ids();

        $fromModel = [
            NamingConcept::RegionSlug,
            NamingConcept::DatacenterSlug,
            NamingConcept::ClusterSlug,
            NamingConcept::MachineName,
            NamingConcept::ProviderInstanceName,
        ];

        foreach ($fromModel as $concept) {
            $this->assertContains(
                $this->policy->example($concept),
                $ids,
                sprintf('The example for %s is not a value the reference topology declares.', $concept->value),
            );
        }
    }

    #[Test]
    public function every_concept_offers_an_example_the_standard_itself_accepts(): void
    {
        // The generator exists so that forms, documents and tests stop
        // inventing their own examples and drifting from the scheme. An
        // example the policy would refuse would be worse than none.
        $offered = 0;

        foreach (NamingConcept::cases() as $concept) {
            $example = $this->policy->example($concept);

            if ($example === null) {
                continue;
            }

            $offered++;

            $this->assertNull(
                $this->policy->problemWith($concept, $example),
                sprintf('The example for %s is not valid under its own rule.', $concept->value),
            );
        }

        $this->assertSame(
            count(NamingConcept::cases()),
            $offered,
            'Every concept should have an example in the model; a missing one means the model and the catalogue disagree.',
        );
    }

    #[Test]
    public function no_example_could_be_mistaken_for_a_production_hostname(): void
    {
        foreach (NamingConcept::cases() as $concept) {
            if ($concept->kind() !== NameKind::NetworkName) {
                continue;
            }

            $example = $this->policy->example($concept);

            $this->assertNotNull($example);
            $this->assertNotNull(
                DnsSuffix::problemWith($example, production: true),
                sprintf('The example hostname for %s would be accepted as production configuration.', $concept->value),
            );
        }
    }

    /**
     * Every hostname-shaped fact in the model, with where it came from.
     *
     * @return iterable<string, string>
     */
    private function hostnameFacts(): iterable
    {
        foreach (ReferenceKind::cases() as $kind) {
            foreach ($this->topology->of($kind) as $object) {
                foreach (['hostname', 'fqdn'] as $fact) {
                    $value = $this->factOrNull($object, $fact);

                    if ($value !== null) {
                        yield sprintf('%s.%s', $object->id, $fact) => $value;
                    }
                }
            }
        }
    }

    private function factOrNull(ReferenceObject $object, string $fact): ?string
    {
        if (! $object->has($fact)) {
            return null;
        }

        $value = $object->stringOrNull($fact);

        return $value === null || trim($value) === '' ? null : $value;
    }
}
