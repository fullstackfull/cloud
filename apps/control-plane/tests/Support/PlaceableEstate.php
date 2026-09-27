<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * The estate rows a VPS plan needs before checkout will sell it.
 *
 * ---------------------------------------------------------------------------
 * Why a great many tests suddenly needed this
 * ---------------------------------------------------------------------------
 *
 * Checkout used to price a plan from the catalogue alone. It now also asks
 * whether this platform's own configuration can say where the thing would be
 * built — a cluster, an address pool, an installable image — and refuses the
 * order when it cannot, because the alternative is taking money for a machine
 * that can never be created.
 *
 * `Product::factory()` makes a VPS, and an empty test database has no estate
 * at all, so every fixture that built "a plan" was building an unsellable one.
 * That is the rule working: the tests were describing a platform that would
 * have charged for an impossible order. What they were *about* — pricing,
 * idempotency, coupons, limits — is unchanged, so they get an estate rather
 * than an exemption.
 *
 * ---------------------------------------------------------------------------
 * Exactly one of each, and only if none exists
 * ---------------------------------------------------------------------------
 *
 * The placement rule resolves a target when a plan names one or when the
 * estate offers exactly one. Two clusters is ambiguity and is refused on
 * purpose — picking the first would place a customer's machine by row order —
 * so this creates a row only when there is none, and a test that deliberately
 * stages a second cluster keeps the ambiguity it set up.
 */
trait PlaceableEstate
{
    /**
     * One active cluster with a node in service, one active public IPv4 pool
     * with a subnet holding a host address, one active image on that cluster.
     * Safe to call more than once.
     *
     * The node and the address are what a sale also asks for (F-07 (c)): a
     * cluster with no node in service, or a pool with no address to hand out,
     * is configuration that cannot deliver, and checkout refuses it.
     */
    protected function estateThatCanPlaceAVps(): ComputeCluster
    {
        /** @var ComputeCluster|null $existing */
        $existing = ComputeCluster::query()->where('status', ClusterStatus::Active)->first();

        $cluster = $existing ?? ComputeCluster::factory()->create(['status' => ClusterStatus::Active]);

        $pools = IpPool::query()
            ->where('is_active', true)
            ->where('ip_version', IpVersion::V4)
            ->count();

        if ($pools === 0) {
            IpPool::factory()->create();
        }

        $nodes = ComputeNode::query()
            ->where('cluster_id', $cluster->getKey())
            ->where('status', NodeStatus::Active)
            ->count();

        if ($nodes === 0) {
            ComputeNode::factory()->create(['cluster_id' => $cluster->getKey()]);
        }

        foreach (IpPool::query()->where('is_active', true)->where('ip_version', IpVersion::V4)->get() as $pool) {
            $hosts = IpAddress::query()
                ->where('status', '!=', IpAddressStatus::Unavailable)
                ->whereIn('subnet_id', Subnet::query()->where('ip_pool_id', $pool->getKey())->where('is_active', true)->select('id'))
                ->count();

            if ($hosts === 0) {
                $subnet = Subnet::factory()->create(['ip_pool_id' => $pool->getKey()]);
                IpAddress::factory()->create(['subnet_id' => $subnet->getKey()]);
            }
        }

        $images = VmTemplate::query()
            ->where('cluster_id', $cluster->getKey())
            ->where('is_active', true)
            ->count();

        if ($images === 0) {
            VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'is_active' => true]);
        }

        return $cluster;
    }

    /**
     * The same estate, committed on a named connection.
     *
     * A test that runs the action on a connection which really commits needs
     * the estate there too, and not in the test transaction the other
     * connection cannot see. Switching the default connection rather than
     * setting one on each model is deliberate: IpPoolFactory writes a region
     * and a datacenter through the query builder on its way to a pool, and a
     * pool committed beside a region that was not would violate the foreign
     * key.
     */
    protected function estateThatCanPlaceAVpsOn(string $connection): void
    {
        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection($connection);

        try {
            $this->estateThatCanPlaceAVps();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
