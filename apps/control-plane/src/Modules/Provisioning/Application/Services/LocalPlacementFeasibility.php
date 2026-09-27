<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Services;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Application\DTOs\PlacementResolution;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingPlacementRequest;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NoHostingCapacityException;
use Lynomia\Modules\SharedHosting\Domain\Services\HostingNodeScheduler;

/**
 * Where a plan would be placed, decided from this platform's own rows.
 *
 * ---------------------------------------------------------------------------
 * Why this is one class and not two copies
 * ---------------------------------------------------------------------------
 *
 * These rules used to live only inside
 * {@see ProvisionOrderedService},
 * which runs after the money has moved. So the platform could sell a Shared
 * Hosting plan with no package behind it, invoice it, take the payment, start
 * a renewal clock — and only then discover it had nothing to ask a panel for.
 * The service sat in PENDING with a reason nobody read.
 *
 * Checkout needed the same answer, and the one thing it must not be given is a
 * second implementation of it: two copies of "can this be placed" drift, and
 * the copy that drifts is the one that takes the money. So the rule moved
 * here, the provisioning path resolves through it, and the checkout path asks
 * it the same question — plus one more, below.
 *
 * ---------------------------------------------------------------------------
 * Two questions: is it configured, and would the fleet take it now
 * ---------------------------------------------------------------------------
 *
 * resolve() asks only about configuration: rows an operator writes and
 * removes. The build path (ProvisionOrderedService) asks this, and when it
 * refuses, the service is kept PENDING with the reason and no job is made,
 * because no retry can conjure a package or a cluster nobody configured.
 *
 * resolveForSale() asks that and then, for Shared Hosting, whether the
 * hosting fleet has a node that would take the package right now, and for a
 * VPS whether the cluster has a node in service and the pool a host address
 * (below). Checkout and the payment-time
 * recheck ask this, before any money moves. The build path deliberately does
 * not: a fleet that is full, loaded or in maintenance at the moment a paid
 * order is fulfilled is a condition that passes, and it belongs to the job —
 * CreateHostingAccountHandler fails it as FailureClass::Capacity and the
 * engine retries it. Asking the fleet at fulfilment would turn a race for the
 * last slot, or a load spike, into a PENDING service with no job that nothing
 * retries (measured by the verifier of round three; pinned by
 * APaidHostingOrderWaitsForCapacityRatherThanStallingTest).
 *
 * ---------------------------------------------------------------------------
 * Local, and deliberately nothing more
 * ---------------------------------------------------------------------------
 *
 * Every question asked here is answered by a row this platform already holds.
 * Nothing contacts a hypervisor, a panel or a registrar, and nothing here
 * claims a machine can actually be built — only that the configuration needed
 * to ask for one exists, and names things that can take it.
 *
 * What is checked, exactly (F-07, as the re-audit after round two found it
 * short of this):
 *
 *  - Shared Hosting: the plan resolves to exactly one package on sale
 *    ({@see HostingPackageForPlan}); and, for a sale only (resolveForSale()),
 *    the hosting fleet has a node the scheduler would place that package on.
 *    That second question is asked of {@see HostingNodeScheduler} itself, from
 *    the node rows it keeps (status, licence, openness, disk, account count,
 *    load, package fit), so checkout refuses the fleet the build's scheduler
 *    would refuse, for the same reasons. It used to be unasked: a package with
 *    zero nodes was sold, paid, built into a FAILED service and renewed.
 *  - VPS: a cluster, a customer IP pool and an OS image. A cluster or pool the
 *    plan declares by id must exist and be one the platform would itself pick
 *    — an ACTIVE cluster, an ACTIVE pool in a customer-allocatable scope. A
 *    declared id used to be trusted as given, so a plan naming an offline
 *    cluster and an inactive pool was sold. With nothing declared, exactly one
 *    such cluster and one such IPv4 pool must exist. And, for a sale only
 *    (resolveForSale()), the cluster must have a compute node in service — a `compute_nodes` row whose status the
 *    scheduler accepts placement on (NodeStatus::acceptsPlacement(), only
 *    ACTIVE) — and the pool an active IPv4 subnet, on a network a customer
 *    machine can be plugged into (Network::canCarryACustomerMachine(): active,
 *    customer-facing, not management, with a bridge), holding a host address
 *    (an `ip_addresses` row not stamped `unavailable`), asked of the allocator
 *    itself (IpAllocator::holdsACustomerAttachableHost()) because the VPS
 *    build reserves with `attachableOnly` and takes from no other subnet. A
 *    pool whose only subnets were on no network, or a bridgeless or
 *    management one, used to be sold and built into ipam.pool_exhausted on
 *    every attempt (F-07, the re-audit of round four). These are rows an operator writes, like a
 *    hosting node: an active cluster with no node, or only nodes still in the
 *    maintenance SyncClusterInventory creates them in, and a pool with no
 *    subnet, used to be sold, paid and built straight into needs_review
 *    (F-07 (c), the re-audit after round three).
 *  - Dedicated: the datacenter, pool and install profile (below).
 *
 * What is not asked here, for a VPS, is room: whether a node in service has
 * the memory and disk free, whether it reported healthy a minute ago, and
 * whether the pool has an address free rather than every host reserved,
 * assigned or quarantined. Those move between checkout and build, and they
 * are the job's — it fails as capacity and the engine retries it — for the
 * reason the build path does not ask the hosting fleet (above).
 *
 * ---------------------------------------------------------------------------
 * Dedicated
 * ---------------------------------------------------------------------------
 *
 * This used to say a Dedicated plan had nothing to resolve up front, and
 * resolved nothing — so an order's job carried the plan's resources alone.
 * ProvisionDedicatedHandler reserves a chassis in a datacenter, reserves its
 * address from a pool and installs from an OS install profile, and the job
 * named none of the three: the reservation looked in datacenter '' and
 * answered no_matching_hardware for ever, and a job that got further died on
 * an undefined array key (F-02, the re-audit's probe). So a Dedicated plan
 * resolves, from rows this platform holds, the datacenter holding its
 * hardware profile, the customer pool in that datacenter and the active
 * install profile — each what the plan's constraints name (and a named one
 * must be one the estate would itself pick), or the estate's only answer —
 * and refuses a profile needing a gateway the pool's subnets may not have
 * (dedicated() says exactly what is checked). Whether a machine of that profile is free is still the
 * handler's question, answered under a row lock, because stock moves between
 * checkout and build. What happens when none is: the job fails as capacity,
 * the engine retries it a bounded number of times, and then the service is
 * handed to a person as needs_review with the money held — not a refusal at
 * checkout, and not an indefinite wait.
 */
final readonly class LocalPlacementFeasibility
{
    public function __construct(
        private HostingPackageForPlan $packages,
        private HostingNodeScheduler $scheduler,
        private InstallProfileRenderer $renderer,
        private IpAllocator $addresses,
    ) {}

    /**
     * Whether a paid line can be sold now: configuration, and for Shared
     * Hosting a fleet that would take it. Checkout and the payment recheck.
     */
    public function resolveForSale(Plan $plan): PlacementResolution
    {
        $resolution = $this->resolve($plan);

        if (! $resolution->isFeasible()) {
            return $resolution;
        }

        if (isset($resolution->values['cluster_id'], $resolution->values['ip_pool_id'])) {
            return $this->vpsInService(
                $resolution,
                (string) $resolution->values['cluster_id'],
                (string) $resolution->values['ip_pool_id'],
            );
        }

        if (! isset($resolution->values['hosting_package_id'])) {
            return $resolution;
        }

        /*
         * Asked of the scheduler the build uses, with the same request the
         * build makes for a line that names no region or panel, so the two
         * cannot disagree about the fleet. Only the answer is kept: the node
         * is chosen again by the build's own scheduler run, and a slot is
         * committed under the node's lock when the account is reserved.
         */
        try {
            $this->scheduler->place(new HostingPlacementRequest(
                packageId: (string) $resolution->values['hosting_package_id'],
            ));
        } catch (NoHostingCapacityException $e) {
            return PlacementResolution::blocked($e->getMessage());
        }

        return $resolution;
    }

    /**
     * Where a plan would be placed, from configuration alone. The build path.
     */
    public function resolve(Plan $plan): PlacementResolution
    {
        /** @var array<string, mixed> $constraints */
        $constraints = $plan->placement_constraints ?? [];
        $kind = $plan->product()->first()?->kind;

        if ($kind === ProductKind::SharedHosting) {
            return $this->hosting($plan);
        }

        if ($kind === ProductKind::Dedicated) {
            return $this->dedicated($plan, $constraints);
        }

        if ($kind !== ProductKind::Vps) {
            return PlacementResolution::ready();
        }

        return $this->compute($constraints);
    }

    /**
     * The catalogue's own mapping from a plan to the quota a panel enforces.
     *
     * The handler refuses a job that does not name one, so a plan without it
     * is a purchase that cannot be delivered however healthy the panel is.
     *
     * Asked of {@see HostingPackageForPlan} rather than of the table. This
     * used to take the first row naming the plan, withdrawn or not, in
     * whatever order the heap held them — so a plan whose package had been
     * replaced could be bought, paid for and built on the one withdrawn
     * (F-32). The resolver takes the package on sale when there is exactly
     * one and refuses otherwise, and its reason is the one blocked here.
     */
    private function hosting(Plan $plan): PlacementResolution
    {
        $choice = $this->packages->resolve((string) $plan->getKey());

        if ($choice->package === null) {
            return PlacementResolution::blocked($choice->reason);
        }

        return PlacementResolution::ready(['hosting_package_id' => (string) $choice->package->getKey()]);
    }

    /**
     * Where a Dedicated build takes its machine, its address and its OS from.
     *
     * The datacenter is the one the plan names, or the only one holding a
     * machine of the plan's hardware profile that is not retired. The pool is
     * the one the plan names, or the only active customer IPv4 pool in that
     * datacenter — a machine is cabled to its building's network, so a pool
     * elsewhere is not a candidate. The install profile is the active one the
     * plan names by slug, or the only active one.
     *
     * A datacenter or pool the plan names is looked up among the same
     * candidates the estate's own answer is drawn from, as the VPS branch
     * does: naming a management pool, an inactive pool, a pool in another
     * building or an id that does not exist is refused, not trusted.
     *
     * And the answer file has to be fillable from the pool: a profile whose
     * template asks for `{{ ipv4_gateway }}`, with no default for it, is
     * refused against a pool holding an active IPv4 subnet registered without
     * a gateway — the build would reserve a machine and an address and then
     * be unable to render (the handler gives both back, but the money should
     * not have moved). That is the one build variable a pool can fail to
     * supply; the others come from the address and the job.
     *
     * @param  array<string, mixed>  $constraints
     */
    private function dedicated(Plan $plan, array $constraints): PlacementResolution
    {
        /** @var array<string, mixed> $resources */
        $resources = $plan->resources ?? [];
        $hardware = is_string($resources['hardware_profile'] ?? null) ? $resources['hardware_profile'] : '';

        $datacenter = self::declaredOrSole(
            $constraints['datacenter_id'] ?? null,
            static fn (): Builder => Datacenter::query()->whereIn(
                'id',
                DedicatedServer::query()
                    ->where('hardware_profile', $hardware)
                    ->where('status', '!=', DedicatedServerStatus::Retired->value)
                    ->select('datacenter_id'),
            ),
        );

        if ($datacenter === false) {
            return PlacementResolution::blocked(
                'the plan names a datacenter that does not exist or holds no machine of its hardware profile',
            );
        }

        if ($datacenter === null) {
            return PlacementResolution::blocked(
                'no single datacenter holds a machine of the plan\'s hardware profile, and the plan names none',
            );
        }

        $pool = self::declaredOrSole(
            $constraints['ip_pool_id'] ?? null,
            static fn (): Builder => IpPool::query()
                ->where('is_active', true)
                ->where('ip_version', 4)
                ->where('datacenter_id', $datacenter)
                ->whereIn('scope', self::customerAllocatableScopes()),
        );

        if ($pool === false) {
            return PlacementResolution::blocked(
                'the plan names an IP pool that does not exist, is not active, is not a customer IPv4 pool, '
                .'or is not in the datacenter holding the hardware',
            );
        }

        if ($pool === null) {
            return PlacementResolution::blocked(
                'no single customer IP pool in the datacenter holding the hardware, and the plan names none',
            );
        }

        $declared = $constraints['os_install_profile_slug'] ?? null;
        $profiles = OsInstallProfile::query()->where('is_active', true);

        /** @var OsInstallProfile|null $profile */
        $profile = is_string($declared) && $declared !== ''
            ? $profiles->where('slug', $declared)->first()
            : (($id = self::soleId($profiles)) === null ? null : OsInstallProfile::query()->find($id));

        if ($profile === null) {
            return PlacementResolution::blocked(
                'the plan names no active OS install profile, and the estate offers no single one',
            );
        }

        if ($this->needsAGatewayThePoolMayNotHave($profile, $pool)) {
            return PlacementResolution::blocked(
                'the install profile needs a gateway and the pool holds a subnet registered without one',
            );
        }

        return PlacementResolution::ready([
            'datacenter_id' => $datacenter,
            'ip_pool_id' => $pool,
            'os_install_profile_id' => (string) $profile->getKey(),
        ]);
    }

    private function needsAGatewayThePoolMayNotHave(OsInstallProfile $profile, string $pool): bool
    {
        $defaults = $profile->defaults ?? [];

        if (($defaults['ipv4_gateway'] ?? null) !== null
            || ! in_array('ipv4_gateway', $this->renderer->placeholdersIn($profile->template), true)) {
            return false;
        }

        return Subnet::query()
            ->where('ip_pool_id', $pool)
            ->where('is_active', true)
            ->where('ip_version', 4)
            ->whereNull('gateway')
            ->exists();
    }

    /**
     * The target the plan declares, when it is one of the candidates; the
     * sole candidate, when it declares nothing.
     *
     * @param  callable(): Builder<*>  $candidates
     * @return string|false|null the id; false when the plan names something that is not a
     *                           candidate; null when it names nothing and there is no sole one
     */
    private static function declaredOrSole(mixed $declared, callable $candidates): string|false|null
    {
        if (is_string($declared) && $declared !== '') {
            return $candidates()->whereKey($declared)->exists() ? $declared : false;
        }

        return self::soleId($candidates());
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function compute(array $constraints): PlacementResolution
    {
        $clusters = static fn (): Builder => ComputeCluster::query()->where('status', ClusterStatus::Active->value);

        $cluster = $this->soleTarget(
            $constraints['cluster_id'] ?? null,
            $clusters,
            static fn (): ?string => self::soleId($clusters()),
        );

        if ($cluster === false) {
            return PlacementResolution::blocked('the plan names a compute cluster that does not exist or is not active');
        }

        if ($cluster === null) {
            return PlacementResolution::blocked('no single active compute cluster, and the plan names none');
        }

        $pools = static fn (): Builder => IpPool::query()
            ->where('is_active', true)
            /*
             * Management addresses reach the hypervisor and BMC control
             * planes, and IpAllocator refuses to hand one to a customer
             * service — so a management pool is not a candidate here, whether
             * the plan names it or not. Counting it would do the damage twice
             * over: with one customer pool beside it the estate would look
             * ambiguous and a perfectly placeable plan would be refused, and
             * alone it would resolve to a placement guaranteed to fail at the
             * allocator.
             */
            ->whereIn('scope', self::customerAllocatableScopes());

        $pool = $this->soleTarget(
            $constraints['ip_pool_id'] ?? null,
            $pools,
            static fn (): ?string => self::soleId(
                $pools()
                    // The estate's own answer is an IPv4 pool; a plan that
                    // names a pool has chosen its family itself.
                    ->where('ip_version', 4)
            ),
        );

        if ($pool === false) {
            return PlacementResolution::blocked('the plan names an IP pool that does not exist, is not active or is not a customer pool');
        }

        if ($pool === null) {
            return PlacementResolution::blocked('no single customer IP pool, and the plan names none');
        }

        $template = $this->templateFor($cluster, $constraints['template_slug'] ?? null);

        if ($template === null) {
            return PlacementResolution::blocked(
                'the plan names no installable OS image, and the cluster offers no single one',
            );
        }

        return PlacementResolution::ready([
            'cluster_id' => $cluster,
            'ip_pool_id' => $pool,
            'storage_class' => $constraints['storage_class'] ?? 'nvme',
            /*
             * Resolved at the purchase and carried as durable values rather
             * than re-derived in the worker. The id is the audit trail; the
             * reference is what the hypervisor is given; the family and the
             * architecture are what the platform reasons with. An operator who
             * stages a second image between payment and build must not change
             * what a paid-for order delivers.
             */
            'template_id' => (string) $template->getKey(),
            'template_reference' => (string) $template->provider_reference,
            'os_family' => $template->os_family->value,
            'architecture' => $template->architecture->value,
        ]);
    }

    /**
     * For a sale only: the cluster has a node in service and the pool a host
     * address. Not asked by the build path, for the reason the hosting fleet
     * is not: the only node going into maintenance between the payment and
     * the build is a wait the job's capacity retries cover, and a PENDING
     * service with no job would not be retried at all.
     */
    private function vpsInService(PlacementResolution $resolution, string $clusterId, string $poolId): PlacementResolution
    {
        if (! $this->hasANodeInService($clusterId)) {
            return PlacementResolution::blocked('the compute cluster has no node in service');
        }

        if (! $this->holdsAHostAddress($poolId)) {
            return PlacementResolution::blocked('the IP pool has no active IPv4 subnet, on a network a customer machine can be attached to, holding a host address');
        }

        return $resolution;
    }

    /**
     * Whether the cluster has a node the scheduler would consider at all.
     *
     * The statuses are asked of the enum NodeScheduler's policy refuses by
     * (NodeStatus::acceptsPlacement()), not listed here. Health, load and room
     * are not asked: they are the scheduler's answer at build time, and a
     * node that is busy now is capacity.
     */
    private function hasANodeInService(string $clusterId): bool
    {
        $inService = array_values(array_map(
            static fn (NodeStatus $status): string => $status->value,
            array_filter(NodeStatus::cases(), static fn (NodeStatus $status): bool => $status->acceptsPlacement()),
        ));

        return ComputeNode::query()
            ->where('cluster_id', $clusterId)
            ->whereIn('status', $inService)
            ->exists();
    }

    /**
     * Whether the pool holds an address a VPS build could ever be given, asked
     * of the allocator the build reserves by
     * (IpAllocator::holdsACustomerAttachableHost()): a row not stamped
     * `unavailable` in an active IPv4 subnet whose network can carry a
     * customer machine (Network::canCarryACustomerMachine()). A host that is
     * reserved, assigned or quarantined counts — the pool is configured, and
     * waiting for one to come free is capacity. A subnet on no network, or on
     * a bridgeless, management, inactive or not customer-facing one, does
     * not: the build reserves with `attachableOnly` and never takes from it.
     */
    private function holdsAHostAddress(string $poolId): bool
    {
        $pool = IpPool::query()->find($poolId);

        return $pool !== null && $this->addresses->holdsACustomerAttachableHost($pool);
    }

    /**
     * The image this plan is sold with, on this cluster.
     *
     * What the plan says, then the estate's own answer when the plan says
     * nothing and there is exactly one answer to give. With two staged images
     * the platform has no basis for choosing, and taking the first would
     * install an operating system by row order.
     */
    private function templateFor(string $clusterId, mixed $declaredSlug): ?VmTemplate
    {
        $query = fn (): Builder => VmTemplate::query()
            ->where('cluster_id', $clusterId)
            ->where('is_active', true);

        if (is_string($declaredSlug) && $declaredSlug !== '') {
            return $query()->where('slug', $declaredSlug)->first();
        }

        /** @var list<VmTemplate> $candidates */
        $candidates = $query()->limit(2)->get()->all();

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The target the plan declares, when it is one of the candidates; the
     * estate's sole candidate, when the plan declares nothing.
     *
     * A declared id is looked up among the same candidates the fallback
     * counts, so naming a target is a choice between them and never a way
     * round them.
     *
     * @param  callable(): Builder<*>  $candidates
     * @param  callable(): ?string  $fallback
     * @return string|false|null the id; false when the plan names something that is not a
     *                           candidate; null when it names nothing and there is no sole one
     */
    private function soleTarget(mixed $declared, callable $candidates, callable $fallback): string|false|null
    {
        if (is_string($declared) && $declared !== '') {
            return $candidates()->whereKey($declared)->exists() ? $declared : false;
        }

        return $fallback();
    }

    /**
     * The scopes IpAllocator will actually allocate from, asked of the enum
     * that decides rather than listed here — a second list would drift from
     * the allocator's, and the drift would be a customer machine on a
     * management address.
     *
     * @return list<string>
     */
    private static function customerAllocatableScopes(): array
    {
        return array_values(array_map(
            static fn (IpPoolScope $scope): string => $scope->value,
            array_filter(
                IpPoolScope::cases(),
                static fn (IpPoolScope $scope): bool => $scope->isCustomerAllocatable(),
            ),
        ));
    }

    /**
     * The id of the only row a query returns, or null when there is not
     * exactly one. With two clusters the platform has no basis for choosing,
     * and picking the first would place a customer's machine by row order.
     *
     * @param  Builder<*>  $query
     */
    private static function soleId(mixed $query): ?string
    {
        $ids = $query->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (string) $ids[0] : null;
    }
}
