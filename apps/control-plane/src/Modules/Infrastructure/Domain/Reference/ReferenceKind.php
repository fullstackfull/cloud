<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Reference;

/**
 * The kinds of thing a reference topology may describe.
 *
 * Every one of these names a concept this repository already models. There is
 * no kind here for something the platform cannot represent: a reference estate
 * that described a product the software does not have would be a wish list, and
 * the point of the reference estate is to be a shape the software can actually
 * be run against.
 *
 * ---------------------------------------------------------------------------
 * Why the enum carries the schema
 * ---------------------------------------------------------------------------
 *
 * {@see requiredFacts()} and {@see refSlots()} are what make one validator able
 * to check every kind without a class per kind. The alternative — a validator
 * with a switch over eighteen cases — puts the shape of the data somewhere
 * other than beside the data's name, and the two drift.
 *
 * A ref slot whose kind is null accepts any kind. Exactly one slot is like that
 * (`observes`, on a monitoring target) because a monitoring target's whole job
 * is to point at something else, whatever that thing is.
 */
enum ReferenceKind: string
{
    case Region = 'region';
    case Datacenter = 'datacenter';
    case Rack = 'rack';
    case Cluster = 'cluster';
    case Node = 'node';
    case Storage = 'storage';
    case Network = 'network';
    case IpPool = 'ip_pool';
    case Subnet = 'subnet';
    case Template = 'template';
    case Machine = 'machine';
    case Chassis = 'chassis';
    case Bmc = 'bmc';
    case HostingNode = 'hosting_node';
    case HostingPackage = 'hosting_package';
    case Provider = 'provider';
    case BackupTarget = 'backup_target';
    case MonitoringTarget = 'monitoring_target';
    case Dependency = 'dependency';

    /**
     * Facts an object of this kind is not an object of this kind without.
     *
     * Short on purpose. This is the list whose absence makes the object
     * meaningless, not the list of everything useful — a validator that
     * demanded every field would make the reference estate impossible to
     * extend, and a validator that demanded none would accept an empty object.
     *
     * @return list<string>
     */
    public function requiredFacts(): array
    {
        return match ($this) {
            self::Region => ['name_en', 'country'],
            self::Datacenter => ['name', 'facility'],
            self::Rack => ['name', 'units'],
            self::Cluster => ['name', 'driver', 'status'],
            self::Node => ['provider_name', 'status', 'cpu_cores', 'memory_mib', 'storage_gib'],
            self::Storage => ['provider_name', 'role', 'storage_class', 'shared', 'total_gib'],
            self::Network => ['slug', 'name', 'purpose'],
            self::IpPool => ['slug', 'name', 'ip_version', 'scope'],
            self::Subnet => ['cidr', 'ip_version', 'prefix_length', 'reference_only'],
            self::Template => ['slug', 'os_family', 'os_version', 'architecture'],
            self::Machine => ['name', 'state', 'safety_class', 'reference_only'],
            self::Chassis => ['serial', 'manufacturer', 'model', 'hardware_profile', 'status'],
            self::Bmc => ['protocol', 'address', 'reference_only'],
            self::HostingNode => ['slug', 'hostname', 'panel', 'status', 'reference_only'],
            self::HostingPackage => ['slug', 'plan_slug', 'panel_package_name'],
            self::Provider => ['name', 'category', 'driver', 'environment'],
            self::BackupTarget => ['datastore', 'verification', 'reference_only'],
            self::MonitoringTarget => ['prometheus_job', 'collectors', 'expected'],
            self::Dependency => ['requires', 'why'],
        };
    }

    /**
     * What this kind hangs off: slot name, the kind it must name, and whether
     * it is required.
     *
     * @return array<string, array{kind: self|null, required: bool}>
     */
    public function refSlots(): array
    {
        $to = static fn (?self $kind, bool $required): array => ['kind' => $kind, 'required' => $required];

        return match ($this) {
            self::Region => [],
            self::Datacenter => ['region' => $to(self::Region, true)],
            self::Rack => ['datacenter' => $to(self::Datacenter, true)],
            self::Cluster => ['datacenter' => $to(self::Datacenter, true)],
            self::Node => [
                'cluster' => $to(self::Cluster, true),
                'rack' => $to(self::Rack, false),
            ],
            self::Storage => [
                'cluster' => $to(self::Cluster, true),
                // Null node is how shared storage says "the whole cluster".
                'node' => $to(self::Node, false),
            ],
            self::Network => ['datacenter' => $to(self::Datacenter, true)],
            self::IpPool => ['datacenter' => $to(self::Datacenter, true)],
            self::Subnet => [
                'ip_pool' => $to(self::IpPool, true),
                'network' => $to(self::Network, true),
            ],
            self::Template => ['cluster' => $to(self::Cluster, true)],
            self::Machine => [
                'datacenter' => $to(self::Datacenter, true),
                'rack' => $to(self::Rack, false),
                'compute_node' => $to(self::Node, false),
                'hosting_node' => $to(self::HostingNode, false),
            ],
            self::Chassis => [
                'datacenter' => $to(self::Datacenter, true),
                'rack' => $to(self::Rack, false),
            ],
            self::Bmc => ['chassis' => $to(self::Chassis, true)],
            self::HostingNode => ['datacenter' => $to(self::Datacenter, true)],
            self::HostingPackage => ['hosting_node' => $to(self::HostingNode, true)],
            self::Provider => ['machine' => $to(self::Machine, false)],
            self::BackupTarget => ['cluster' => $to(self::Cluster, true)],
            // Any kind, because that is the question it answers.
            self::MonitoringTarget => ['observes' => $to(null, true)],
            self::Dependency => [
                'datacenter' => $to(self::Datacenter, true),
                // A ref rather than a fact, so that a dependency claiming to
                // be satisfied by a provider that is not in the estate is a
                // graph error rather than a string nobody checked.
                'satisfied_by' => $to(self::Provider, false),
            ],
        };
    }

    /**
     * Does an object of this kind carry a network address?
     *
     * Everything that answers yes must state `reference_only` and may only
     * carry addresses from a range reserved for documentation. The validator
     * enforces both, which is what stops somebody pasting a real management
     * address into a committed file while adding one machine.
     */
    public function carriesAddresses(): bool
    {
        return match ($this) {
            self::Subnet, self::Machine, self::Bmc => true,
            default => false,
        };
    }
}
