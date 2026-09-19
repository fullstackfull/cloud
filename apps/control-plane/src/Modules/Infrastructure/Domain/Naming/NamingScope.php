<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

/**
 * Where a name has to be unique — which is not "everywhere" for most of them.
 *
 * Every scope here was read off the unique indexes this database already has,
 * not decided here. Two racks in two datacenters may both be `A1`, because
 * that is how racks are labelled in the real world and `datacenter_id, name` is
 * the index that says so. Two templates called `debian-stable` on two clusters
 * are two staged images of the same thing, and `cluster_id, slug` says that
 * too. A policy that demanded global uniqueness would refuse both, and an
 * operator would work around it by inventing a prefix.
 */
enum NamingScope: string
{
    /** One value across the whole platform. */
    case Platform = 'platform';

    /** One value per datacenter. */
    case Datacenter = 'datacenter';

    /** One value per cluster. */
    case Cluster = 'cluster';

    /** One value per cluster and node — a node-local storage name. */
    case ClusterNode = 'cluster_node';

    /** Not unique at all, and not expected to be: display names, mostly. */
    case NotUnique = 'not_unique';

    /**
     * The columns the uniqueness is judged across, this one included.
     *
     * @return list<string>
     */
    public function keyColumns(string $column): array
    {
        return match ($this) {
            self::Platform => [$column],
            self::Datacenter => ['datacenter_id', $column],
            self::Cluster => ['cluster_id', $column],
            self::ClusterNode => ['cluster_id', 'node_id', $column],
            self::NotUnique => [],
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Platform => 'unique across the platform',
            self::Datacenter => 'unique within its datacenter',
            self::Cluster => 'unique within its cluster',
            self::ClusterNode => 'unique within its cluster and node',
            self::NotUnique => 'not required to be unique',
        };
    }
}
