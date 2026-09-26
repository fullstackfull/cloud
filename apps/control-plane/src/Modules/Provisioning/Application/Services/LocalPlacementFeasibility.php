<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Services;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Application\DTOs\PlacementResolution;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;

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
 * it the same question.
 *
 * ---------------------------------------------------------------------------
 * Local, and deliberately nothing more
 * ---------------------------------------------------------------------------
 *
 * Every question asked here is answered by a row this platform already holds.
 * Nothing contacts a hypervisor, a panel or a registrar, and nothing here
 * claims a machine can actually be built — only that the configuration needed
 * to ask for one exists. Whether a node has room is a provider's answer and a
 * different phase's problem.
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
 * handler's question, answered under a row lock: stock moves between checkout
 * and build, and a capacity wait is the right outcome for a machine that is
 * busy, not a refusal at checkout.
 */
final readonly class LocalPlacementFeasibility
{
    public function __construct(
        private HostingPackageForPlan $packages,
        private InstallProfileRenderer $renderer,
    ) {}

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
        $cluster = $this->soleTarget(
            $constraints['cluster_id'] ?? null,
            static fn (): ?string => self::soleId(ComputeCluster::query()->where('status', 'active')),
        );

        if ($cluster === null) {
            return PlacementResolution::blocked('no single active compute cluster, and the plan names none');
        }

        $pool = $this->soleTarget(
            $constraints['ip_pool_id'] ?? null,
            static fn (): ?string => self::soleId(
                IpPool::query()
                    ->where('is_active', true)
                    ->where('ip_version', 4)
                    /*
                     * Management addresses reach the hypervisor and BMC
                     * control planes, and IpAllocator refuses to hand one to
                     * a customer service — so a management pool is not a
                     * candidate here either. Counting it would do the damage
                     * twice over: with one customer pool beside it the estate
                     * would look ambiguous and a perfectly placeable plan
                     * would be refused, and alone it would resolve to a
                     * placement guaranteed to fail at the allocator.
                     */
                    ->whereIn('scope', self::customerAllocatableScopes()),
            ),
        );

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
     * @param  callable(): ?string  $fallback
     */
    private function soleTarget(mixed $declared, callable $fallback): ?string
    {
        if (is_string($declared) && $declared !== '') {
            return $declared;
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
