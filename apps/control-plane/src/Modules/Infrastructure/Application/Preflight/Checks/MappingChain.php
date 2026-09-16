<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight\Checks;

use Illuminate\Support\Collection;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * The mappings a product needs before its first order, which a provider
 * connection test says nothing about.
 *
 * ===========================================================================
 * WHY THIS IS A SEPARATE CHAIN FROM THE PROVIDER ONE
 * ===========================================================================
 *
 * Because a perfectly healthy provider is not a sellable product. A Proxmox
 * cluster can answer an authenticated read, offer every capability, hold a
 * valid licence and still be unable to build a single virtual machine —
 * because nobody has mapped a storage pool to it, or there is no template on
 * it, or every node is draining. None of that is visible to a connection test,
 * and all of it is visible here.
 *
 * These are the cheapest checks in the whole preflight and they catch the
 * failures that otherwise surface as a customer's order sitting in a queue.
 *
 * ===========================================================================
 * READS, AND ONLY THE MODELS' OWN QUESTIONS
 * ===========================================================================
 *
 * Every question here is asked through a scope or method the models already
 * have — `scopeSchedulable`, `canHost`, `scopeInstallable`, `acceptsPlacement`
 * — rather than by writing a second set of placement rules. A preflight that
 * decided for itself which nodes are eligible would answer differently from
 * the scheduler that actually places the machine, and the discrepancy would
 * only show up on the order that failed.
 */
final readonly class MappingChain
{
    /**
     * @return list<PreflightFinding>
     */
    public function inspect(Product $product): array
    {
        return match ($product) {
            Product::Vps, Product::GpuCompute => $this->compute($product),
            Product::Dedicated => $this->dedicated(),
            Product::SharedHosting, Product::WordPress => $this->hosting($product),
            Product::Domains => $this->domains(),
            /*
             * The prepared products. Their mappings do not exist to check
             * because no adapter for them exists either — the categories were
             * added so a product could name what it will need, and saying so
             * is more honest than inventing a mapping check for a thing with
             * no provider.
             */
            default => [PreflightFinding::notApplicable(
                'mapping.none',
                CheckCategory::Mapping,
                $product->value,
                'This product has no infrastructure mappings in this build.',
            )],
        };
    }

    /**
     * Compute: a cluster that takes placement, a node that can hold something,
     * storage that can hold a disk, a template that can be installed, and
     * addresses to give out.
     *
     * @return list<PreflightFinding>
     */
    private function compute(Product $product): array
    {
        $findings = [];
        $target = $product->value;

        $clusters = ComputeCluster::query()->schedulable()->get();

        if ($clusters->isEmpty()) {
            $total = ComputeCluster::query()->count();

            return [PreflightFinding::fail(
                'mapping.cluster',
                CheckCategory::Mapping,
                $target,
                $total === 0
                    ? 'No compute cluster is registered.'
                    : sprintf('%d compute cluster(s) are registered and none of them accepts placement.', $total),
                $total === 0
                    ? 'Register a compute cluster and bind it to a datacenter.'
                    : 'Return a cluster to active status, or register one that accepts placement.',
            )];
        }

        $findings[] = PreflightFinding::pass(
            'mapping.cluster',
            CheckCategory::Mapping,
            $target,
            sprintf('%d cluster(s) accept placement, across %d datacenter(s).',
                $clusters->count(),
                $clusters->pluck('datacenter_id')->unique()->count()),
            EvidenceClass::Configuration,
        );

        $clusterIds = $clusters->modelKeys();

        // ---- eligible nodes ------------------------------------------------
        $nodes = ComputeNode::query()->schedulable()->whereIn('cluster_id', $clusterIds)->get();

        $findings[] = $nodes->isEmpty()
            ? PreflightFinding::fail(
                'mapping.nodes',
                CheckCategory::Mapping,
                $target,
                'No node on any placeable cluster is schedulable.',
                'Bring a node back to active and healthy, or run infrastructure:reconcile to refresh what the cluster reports.',
            )
            : PreflightFinding::pass(
                'mapping.nodes',
                CheckCategory::Mapping,
                $target,
                sprintf('%d node(s) are eligible for placement.', $nodes->count()),
                EvidenceClass::Configuration,
            );

        // ---- storage --------------------------------------------------------
        $storages = ComputeStorage::query()->whereIn('cluster_id', $clusterIds)->where('is_active', true)->get();

        $findings[] = $storages->isEmpty()
            ? PreflightFinding::fail(
                'mapping.storage',
                CheckCategory::Mapping,
                $target,
                'No active storage is mapped to any placeable cluster, so a disk cannot be allocated.',
                'Map a storage pool to the cluster, then run infrastructure:reconcile to record its capacity.',
            )
            : PreflightFinding::pass(
                'mapping.storage',
                CheckCategory::Mapping,
                $target,
                sprintf('%d active storage pool(s) are mapped, across storage classes: %s.',
                    $storages->count(),
                    implode(', ', $storages->map(static fn (ComputeStorage $s): string => $s->storage_class->value)->unique()->sort()->values()->all())),
                EvidenceClass::Configuration,
            );

        // ---- capacity -------------------------------------------------------
        $findings[] = $this->capacityFinding($nodes, $storages, $target);

        // ---- template -------------------------------------------------------
        /*
         * Installable, and separately: active but with no name the cluster
         * knows it by.
         *
         * `scopeInstallable` already requires a provider reference, which is
         * the platform refusing to consider such a template buildable — so
         * counting both tells the operator which of two different things to
         * do. "No template is mapped" means map one. "Three are mapped and
         * none carries a provider reference" means go and read the identifier
         * off the cluster, because nothing in this platform invents one, and a
         * template the hypervisor has no name for fails at build time, per
         * order, which is the worst possible moment.
         */
        $templates = VmTemplate::query()->installable()->get();

        if ($templates->isNotEmpty()) {
            $findings[] = PreflightFinding::pass(
                'mapping.template',
                CheckCategory::Mapping,
                $target,
                sprintf('%d installable template(s): %s.',
                    $templates->count(),
                    implode(', ', $templates->take(5)->map(fn (VmTemplate $t): string => $t->os_family->value.' '.$t->os_version)->values()->all())),
                EvidenceClass::Configuration,
            );

            return $findings;
        }

        $unreferenced = VmTemplate::query()
            ->where('is_active', true)
            ->whereNull('provider_reference')
            ->count();

        $findings[] = $unreferenced > 0
            ? PreflightFinding::fail(
                'mapping.template',
                CheckCategory::Mapping,
                $target,
                sprintf(
                    '%d active template(s) are mapped and none carries a provider reference, so the hypervisor has no name for any of them.',
                    $unreferenced,
                ),
                'Record each template\'s provider reference, read off the cluster rather than chosen.',
            )
            : PreflightFinding::fail(
                'mapping.template',
                CheckCategory::Mapping,
                $target,
                'No installable template is mapped, so nothing can be built.',
                'Map a template and record the provider reference it is known by on the cluster.',
            );

        // ---- addresses ------------------------------------------------------
        $pools = IpPool::query()->count();

        $findings[] = $pools === 0
            ? PreflightFinding::fail(
                'mapping.network',
                CheckCategory::Mapping,
                $target,
                'No address pool is registered, so a machine cannot be given an address.',
                'Register an address pool and its subnets.',
            )
            : PreflightFinding::pass(
                'mapping.network',
                CheckCategory::Mapping,
                $target,
                sprintf('%d address pool(s) are registered.', $pools),
                EvidenceClass::Configuration,
            );

        return $findings;
    }

    /**
     * Whether anything could actually be placed.
     *
     * Reported in three states rather than two, because "unknown" is a real
     * and common answer: a cluster that has never been reconciled reports no
     * capacity at all, and calling that "insufficient" would send an operator
     * to buy hardware they already have.
     *
     * The arithmetic is the models' own — `freeCpuCores`, `freeMemoryMib`,
     * `freeGib` — so that preflight and the scheduler agree. No overcommit
     * rule is invented here; the ratio and the headroom live on the node row
     * where an operator set them.
     *
     * @param  Collection<int, ComputeNode>  $nodes
     * @param  Collection<int, ComputeStorage>  $storages
     */
    private function capacityFinding(
        Collection $nodes,
        Collection $storages,
        string $target,
    ): PreflightFinding {
        if ($nodes->isEmpty()) {
            return PreflightFinding::notTested(
                'mapping.capacity',
                CheckCategory::Mapping,
                $target,
                'Not established: no node is eligible for placement, so there is no capacity to measure.',
            );
        }

        $neverSeen = $nodes->filter(static fn (ComputeNode $node): bool => $node->last_seen_at === null);

        if ($neverSeen->count() === $nodes->count()) {
            return PreflightFinding::warning(
                'mapping.capacity',
                CheckCategory::Mapping,
                $target,
                sprintf('Capacity is unknown: none of the %d eligible node(s) has ever been reconciled.', $nodes->count()),
                'Run infrastructure:reconcile so the cluster reports what its nodes actually have.',
            );
        }

        $freeCores = $nodes->sum(static fn (ComputeNode $node): int => max(0, $node->freeCpuCores()));
        $freeMemory = $nodes->sum(static fn (ComputeNode $node): int => max(0, $node->freeMemoryMib()));
        $freeStorage = $storages->sum(static fn (ComputeStorage $storage): int => (int) ($storage->freeGib() ?? 0));

        if ($freeCores <= 0 || $freeMemory <= 0) {
            return PreflightFinding::fail(
                'mapping.capacity',
                CheckCategory::Mapping,
                $target,
                sprintf('No eligible node has room: %d core(s) and %d MiB free across %d node(s).', $freeCores, $freeMemory, $nodes->count()),
                'Add a node, or drain allocations from an existing one.',
            );
        }

        return PreflightFinding::pass(
            'mapping.capacity',
            CheckCategory::Mapping,
            $target,
            sprintf('%d core(s), %d MiB and %d GiB free across %d eligible node(s).', $freeCores, $freeMemory, $freeStorage, $nodes->count()),
            EvidenceClass::Configuration,
        );
    }

    /**
     * Dedicated: a machine that exists, is classified to be used, and has a
     * controller bound to it.
     *
     * No simulated hardware satisfies this, in either mode. A chassis is
     * either racked or it is not, and a fake BMC answering a power query is
     * evidence about this codebase.
     *
     * @return list<PreflightFinding>
     */
    private function dedicated(): array
    {
        $findings = [];
        $target = Product::Dedicated->value;

        $servers = ManagedServer::query()->with('providerInstances')->get();

        if ($servers->isEmpty()) {
            return [PreflightFinding::blocked(
                'mapping.machine',
                CheckCategory::Hardware,
                $target,
                'No machine is registered, and a dedicated server is a machine.',
                BlockerReason::Hardware,
                'Rack a machine, register it, and classify it.',
            )];
        }

        $usable = $servers->filter(
            static fn (ManagedServer $server): bool => $server->safety_class->permits(
                InfrastructureAction::Reimage,
            ) && $server->allow_reimage,
        );

        $findings[] = $usable->isEmpty()
            ? PreflightFinding::blocked(
                'mapping.machine',
                CheckCategory::Hardware,
                $target,
                sprintf('%d machine(s) are registered and none is cleared for reimaging, which is what selling one requires.', $servers->count()),
                BlockerReason::Hardware,
                'Classify a machine as reimage-allowed and clear it for reimaging. Two separate acts, on purpose.',
            )
            : PreflightFinding::pass(
                'mapping.machine',
                CheckCategory::Hardware,
                $target,
                sprintf('%d of %d machine(s) are cleared for reimaging.', $usable->count(), $servers->count()),
                EvidenceClass::Configuration,
            );

        $withBmc = $servers->filter(static fn (ManagedServer $server): bool => $server->bmc() !== null);

        $findings[] = $withBmc->isEmpty()
            ? PreflightFinding::blocked(
                'mapping.bmc',
                CheckCategory::Hardware,
                $target,
                'No machine has a baseboard management controller bound to it, so none can be powered or reinstalled.',
                BlockerReason::Hardware,
                'Register the machine\'s BMC as a provider and bind it to the machine.',
            )
            : PreflightFinding::pass(
                'mapping.bmc',
                CheckCategory::Hardware,
                $target,
                sprintf('%d machine(s) have a controller bound.', $withBmc->count()),
                EvidenceClass::Configuration,
            );

        return $findings;
    }

    /**
     * Hosting: a node to place an account on, and a package to place it under.
     *
     * @return list<PreflightFinding>
     */
    private function hosting(Product $product): array
    {
        $findings = [];
        $target = $product->value;

        $nodes = HostingNode::query()->get();
        $live = $nodes->filter(
            static fn (HostingNode $node): bool => $node->status === HostingNodeStatus::Active,
        );

        $findings[] = $live->isEmpty()
            ? PreflightFinding::fail(
                'mapping.hosting_node',
                CheckCategory::Mapping,
                $target,
                $nodes->isEmpty()
                    ? 'No hosting node is registered.'
                    : sprintf('%d hosting node(s) are registered and none is active.', $nodes->count()),
                $nodes->isEmpty()
                    ? 'Register a hosting node, which the node install preflight gates separately.'
                    : 'Return a hosting node to active, or investigate why it left.',
            )
            : PreflightFinding::pass(
                'mapping.hosting_node',
                CheckCategory::Mapping,
                $target,
                sprintf('%d active hosting node(s), panels: %s.',
                    $live->count(),
                    implode(', ', $live->map(static fn (HostingNode $node): string => $node->panel->value)->unique()->values()->all())),
                EvidenceClass::Configuration,
            );

        $packages = HostingPackage::query()->count();

        $findings[] = $packages === 0
            ? PreflightFinding::fail(
                'mapping.hosting_package',
                CheckCategory::Mapping,
                $target,
                'No hosting package is mapped, so an account has no plan to be created under.',
                'Map a hosting package to each plan that is sold.',
            )
            : PreflightFinding::pass(
                'mapping.hosting_package',
                CheckCategory::Mapping,
                $target,
                sprintf('%d hosting package(s) are mapped.', $packages),
                EvidenceClass::Configuration,
            );

        /*
         * The node install gate is a different question and is named rather
         * than folded in. `hosting:preflight` judges facts collected **on** a
         * target machine — its operating system, what is already listening,
         * what DNS says about its own name, what the vendor says about its
         * licence — before a panel is installed. This preflight judges a node
         * the platform already manages. Neither can answer the other's
         * question, and one command pretending to answer both would be worse
         * at each.
         */
        $findings[] = PreflightFinding::notApplicable(
            'mapping.hosting_node_install',
            CheckCategory::Mapping,
            $target,
            'Whether a machine may have a panel installed on it is gated separately by `hosting:preflight`, which judges facts collected on the machine itself before installation.',
        );

        return $findings;
    }

    /**
     * Domains: a registry this platform can actually sell names in.
     *
     * @return list<PreflightFinding>
     */
    private function domains(): array
    {
        $target = Product::Domains->value;

        $tlds = DomainTld::query()->count();
        $sellable = DomainTld::query()->where('enabled', true)->where('allows_registration', true)->count();

        if ($tlds === 0) {
            return [PreflightFinding::fail(
                'mapping.tld',
                CheckCategory::Mapping,
                $target,
                'No top-level domain is catalogued, so there is nothing to sell.',
                'Catalogue at least one TLD with its registry, pricing and capability support.',
            )];
        }

        return [$sellable === 0
            ? PreflightFinding::fail(
                'mapping.tld',
                CheckCategory::Mapping,
                $target,
                sprintf('%d TLD(s) are catalogued and none is both enabled and open to registration.', $tlds),
                'Enable a catalogued TLD once its registry contract and pricing are in place.',
            )
            : PreflightFinding::pass(
                'mapping.tld',
                CheckCategory::Mapping,
                $target,
                sprintf('%d of %d catalogued TLD(s) are enabled and open to registration.', $sellable, $tlds),
                EvidenceClass::Configuration,
            )];
    }
}
