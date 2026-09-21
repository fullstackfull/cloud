<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Services;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Application\DTOs\PlacementResolution;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

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
 * A Dedicated plan is not gated. It reserves a chassis from inventory inside
 * its own handler and has no catalogue mapping to resolve up front, so there
 * is nothing here that could truthfully be checked — and refusing it for want
 * of an invented mapping would turn a sellable product into an unsellable one.
 */
final readonly class LocalPlacementFeasibility
{
    public function resolve(Plan $plan): PlacementResolution
    {
        /** @var array<string, mixed> $constraints */
        $constraints = $plan->placement_constraints ?? [];
        $kind = $plan->product()->first()?->kind;

        if ($kind === ProductKind::SharedHosting) {
            return $this->hosting($plan);
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
     */
    private function hosting(Plan $plan): PlacementResolution
    {
        $package = HostingPackage::query()->where('plan_id', $plan->getKey())->first();

        if ($package === null) {
            return PlacementResolution::blocked(
                'the plan names no hosting package, so no panel quota can be applied',
            );
        }

        return PlacementResolution::ready(['hosting_package_id' => (string) $package->getKey()]);
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
            static fn (): ?string => self::soleId(IpPool::query()->where('is_active', true)->where('ip_version', 4)),
        );

        if ($pool === null) {
            return PlacementResolution::blocked('no single IP pool, and the plan names none');
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
