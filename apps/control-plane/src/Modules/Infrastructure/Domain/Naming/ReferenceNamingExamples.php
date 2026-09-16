<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use Throwable;

/**
 * A safe example of each kind of name, read out of the modelled estate.
 *
 * ===========================================================================
 * WHY THESE ARE NOT WRITTEN DOWN HERE
 * ===========================================================================
 *
 * The first version of this listed the examples as literals — `ref-dc-alpha-1`,
 * `debian-stable`, `RA1` — and Gap 4's architecture gate rejected it within the
 * minute, correctly. A reference identifier in application source is a
 * reference identifier in application source, whatever the method around it is
 * called, and the rule exists because a real estate must need no source edit.
 *
 * The rejection was also pointing at a better design. An example's whole job is
 * to be a value the scheme actually produces, so the place to get one is the
 * file that declares the scheme: `resources/reference-topology/topology.php`.
 * Read it, and an example cannot drift from the model, cannot be a fourth
 * naming authority hiding in a helper, and follows the model when it is
 * renamed.
 *
 * ===========================================================================
 * NULL IS AN ANSWER
 * ===========================================================================
 *
 * A caller asking for an example is offering help — a hint under a form field,
 * a sentence in an audit finding. If the model cannot be read, the honest
 * answer is that there is no example to show, not an invented one. Every
 * caller treats null as "say nothing more", which is why the return type says
 * so.
 */
final class ReferenceNamingExamples
{
    private ?ReferenceTopology $topology = null;

    private bool $attempted = false;

    /**
     * What a valid value for this field looks like, or null if the model
     * cannot be read or declares nothing of that kind.
     */
    public function for(NamingConcept $concept): ?string
    {
        $topology = $this->topology();

        if ($topology === null) {
            return null;
        }

        [$kind, $fact] = $this->source($concept);

        $objects = $topology->of($kind);

        if ($objects === []) {
            return null;
        }

        $object = $objects[0];

        // `null` means the object's own logical id rather than one of its
        // facts, which is what a logical key example is.
        if ($fact === null) {
            return $object->id;
        }

        if (! $object->has($fact)) {
            return null;
        }

        $value = $object->stringOrNull($fact);

        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * Which object in the model carries an example of this concept, and which
     * of its facts is the example.
     *
     * @return array{ReferenceKind, ?string}
     */
    private function source(NamingConcept $concept): array
    {
        return match ($concept) {
            NamingConcept::RegionSlug => [ReferenceKind::Region, null],
            NamingConcept::DatacenterSlug => [ReferenceKind::Datacenter, null],
            NamingConcept::DatacenterDisplayName => [ReferenceKind::Datacenter, 'name'],
            NamingConcept::RackCode => [ReferenceKind::Rack, 'name'],
            NamingConcept::ClusterSlug => [ReferenceKind::Cluster, null],
            NamingConcept::NodeProviderName => [ReferenceKind::Node, 'provider_name'],
            NamingConcept::StorageProviderName => [ReferenceKind::Storage, 'provider_name'],
            NamingConcept::TemplateSlug => [ReferenceKind::Template, 'slug'],
            NamingConcept::TemplateProviderReference => [ReferenceKind::Template, 'provider_reference'],
            NamingConcept::HostingNodeSlug => [ReferenceKind::HostingNode, null],
            NamingConcept::HostingNodeHostname => [ReferenceKind::HostingNode, 'hostname'],
            NamingConcept::MachineName => [ReferenceKind::Machine, null],
            NamingConcept::ProviderInstanceName => [ReferenceKind::Provider, null],
        };
    }

    /**
     * The model, loaded at most once, and never fatally.
     *
     * A form hint that could not be rendered is not a reason to fail the
     * request it was part of.
     */
    private function topology(): ?ReferenceTopology
    {
        if ($this->attempted) {
            return $this->topology;
        }

        $this->attempted = true;

        try {
            $this->topology = ReferenceTopology::load();
        } catch (Throwable) {
            // Including an invalid topology, an unreadable file, and being
            // called outside a booted application: a form hint that could not
            // be rendered is not a reason to fail the request it was part of.
            $this->topology = null;
        }

        return $this->topology;
    }
}
