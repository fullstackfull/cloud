<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

/**
 * Every infrastructure field this platform names something with, and what kind
 * of name it holds.
 *
 * ===========================================================================
 * THIS IS AN INVENTORY, NOT A DESIGN
 * ===========================================================================
 *
 * One case per (table, column) that already exists, with the kind, the scope
 * and the ceiling taken from the schema rather than chosen here. That is
 * deliberate: a naming standard that describes a hierarchy the database does
 * not have would be a second model of the estate, and the platform already has
 * one of those in resources/reference-topology/topology.php.
 *
 * What the standard adds is the answer to "which of the four kinds is this",
 * which the columns cannot say for themselves. `compute_nodes.provider_name`
 * and `compute_clusters.slug` are both varchar(255) and they are not the same
 * kind of thing at all: one is Proxmox's word for a node and is sent back to
 * Proxmox, the other is ours and is what an order points at.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY ABSENT
 * ===========================================================================
 *
 * `virtual_machines.hostname` is a customer's hostname for a customer's
 * machine. It is resource data, it follows the customer's naming, and holding
 * it to an operator naming standard would refuse names customers are entitled
 * to. The same goes for domains, DNS zones and WordPress site names.
 *
 * `dedicated_servers.serial` is a manufacturer's asset identifier. It is not a
 * name the platform composes, and it is never a hostname: a serial in a
 * customer-visible name publishes the hardware inventory.
 *
 * Note what is also absent: examples. An example of a valid value belongs to
 * the file that declares the scheme, and
 * {@see ReferenceNamingExamples} reads them from there. Writing them here would
 * put reference identifiers into application source, which is the thing Gap 4's
 * architecture gate exists to refuse — and it refused exactly that, the first
 * time this enum tried it.
 */
enum NamingConcept: string
{
    case RegionSlug = 'region.slug';
    case DatacenterSlug = 'datacenter.slug';
    case DatacenterDisplayName = 'datacenter.name';
    case RackCode = 'rack.name';
    case ClusterSlug = 'cluster.slug';
    case NodeProviderName = 'compute_node.provider_name';
    case StorageProviderName = 'compute_storage.provider_name';
    case TemplateSlug = 'vm_template.slug';
    case TemplateProviderReference = 'vm_template.provider_reference';
    case HostingNodeSlug = 'hosting_node.slug';
    case HostingNodeHostname = 'hosting_node.hostname';
    case MachineName = 'managed_server.name';
    case ProviderInstanceName = 'provider_instance.name';

    public function kind(): NameKind
    {
        return match ($this) {
            self::RegionSlug,
            self::DatacenterSlug,
            self::ClusterSlug,
            self::TemplateSlug,
            self::HostingNodeSlug => NameKind::LogicalKey,

            // A rack code is stencilled on a physical cabinet and a machine
            // name is what an operator calls a machine on a screen. Unique,
            // ours, and chosen by a person who may choose again — which is a
            // weaker promise than a logical key makes, and the reason these
            // are a separate kind rather than lumped in above.
            self::RackCode,
            self::MachineName,
            self::ProviderInstanceName => NameKind::OperatorCode,

            self::NodeProviderName,
            self::StorageProviderName,
            self::TemplateProviderReference => NameKind::ProviderNative,

            self::HostingNodeHostname => NameKind::NetworkName,

            self::DatacenterDisplayName => NameKind::DisplayName,
        };
    }

    /**
     * The column's own ceiling.
     *
     * Read from the migrations: every one of these is varchar(255). Stated per
     * concept anyway, because the day one of them is a varchar(64) the policy
     * should follow the column rather than the column silently truncating what
     * the policy accepted.
     */
    public function maxLength(): int
    {
        return 255;
    }

    public function scope(): NamingScope
    {
        return match ($this) {
            self::RegionSlug,
            self::DatacenterSlug,
            self::ClusterSlug,
            self::HostingNodeSlug,
            self::MachineName,
            self::ProviderInstanceName => NamingScope::Platform,

            self::RackCode => NamingScope::Datacenter,

            self::NodeProviderName,
            self::TemplateSlug => NamingScope::Cluster,

            self::StorageProviderName => NamingScope::ClusterNode,

            // A hosting node's hostname is not indexed as unique today: the
            // slug is the identity, and two rows may legitimately point at one
            // name while a node is being replaced. Stated rather than assumed.
            self::HostingNodeHostname,
            self::TemplateProviderReference,
            self::DatacenterDisplayName => NamingScope::NotUnique,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::RegionSlug => 'regions',
            self::DatacenterSlug, self::DatacenterDisplayName => 'datacenters',
            self::RackCode => 'racks',
            self::ClusterSlug => 'compute_clusters',
            self::NodeProviderName => 'compute_nodes',
            self::StorageProviderName => 'compute_storages',
            self::TemplateSlug, self::TemplateProviderReference => 'vm_templates',
            self::HostingNodeSlug, self::HostingNodeHostname => 'hosting_nodes',
            self::MachineName => 'managed_servers',
            self::ProviderInstanceName => 'provider_instances',
        };
    }

    public function column(): string
    {
        return match ($this) {
            self::RegionSlug, self::DatacenterSlug, self::ClusterSlug,
            self::TemplateSlug, self::HostingNodeSlug => 'slug',
            self::DatacenterDisplayName, self::RackCode, self::MachineName,
            self::ProviderInstanceName => 'name',
            self::NodeProviderName, self::StorageProviderName => 'provider_name',
            self::TemplateProviderReference => 'provider_reference',
            self::HostingNodeHostname => 'hostname',
        };
    }

    /**
     * Every concept whose values live in a table this platform can read.
     *
     * @return list<self>
     */
    public static function persisted(): array
    {
        return self::cases();
    }
}
