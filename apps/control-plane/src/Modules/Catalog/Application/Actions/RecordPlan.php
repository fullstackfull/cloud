<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * An operator records a plan: one way a product is sold.
 *
 * ===========================================================================
 * WHAT A PLAN MUST SAY, AND WHY THE ANSWER DEPENDS ON THE KIND
 * ===========================================================================
 *
 * `resources` is jsonb on purpose — a VPS plan describes vCPU, memory and
 * disk, a hosting plan describes quota a panel enforces, a dedicated plan
 * names a hardware profile — and forcing all three into columns would be a
 * wall of nullable ones. The schema says so and it is right.
 *
 * Free-form is not the same as optional, though, and two kinds have a specific
 * hazard that is worth refusing here rather than discovering in an order:
 *
 *   - **A VPS plan with no compute triple silently sells a small one.**
 *     `CreateVpsHandler` reads `$payload['vcpu'] ?? 1`, `memory_mib ?? 1024`
 *     and `disk_gib ?? 10`. A plan saved without them does not fail; it builds
 *     a one-vCPU machine and invoices whatever the price said. The customer
 *     gets less than the page offered and nothing anywhere reports a problem.
 *
 *   - **A dedicated plan with no hardware profile looks like a capacity
 *     problem for ever.** `ProvisionDedicatedHandler` passes
 *     `$payload['hardware_profile'] ?? ''` to the reservation, which answers
 *     `NoMatchingHardwareException` — a capacity error, retried rather than
 *     refused. The order waits on a machine that was never going to match,
 *     and the operator is looking at the rack instead of at the plan.
 *
 * Shared hosting has no such requirement here, deliberately: what a hosting
 * plan sells is enforced by the panel package it maps to, and that lives on
 * {@see HostingPackage}.
 * Requiring a second copy of the quota on the plan would create two answers to
 * one question.
 *
 * ===========================================================================
 * A PLAN CANNOT OUTLIVE ITS PRODUCT'S KIND
 * ===========================================================================
 *
 * The product is read, not taken on trust, and the plan's requirements come
 * from that product's kind. Nothing here can make a plan sellable: readiness
 * is decided by {@see ProductSellability},
 * which reads the software state and the readiness row and has never read a
 * plan.
 */
final readonly class RecordPlan
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>|null  $description
     * @param  array<string, mixed>  $resources
     * @param  array<string, mixed>|null  $placementConstraints
     *
     * @throws CatalogueRefused
     */
    public function execute(
        Product $product,
        string $slug,
        array $name,
        ?array $description,
        array $resources,
        ?array $placementConstraints,
        ?int $stockLimit,
        ?int $perCustomerLimit,
        bool $isActive,
        bool $isPublic,
        int $sortOrder,
        User $operator,
    ): Plan {
        $this->assertResourcesSuit($product->kind, $resources);

        return $this->record->execute(
            act: fn (): Plan => DB::transaction(function () use (
                $product,
                $slug,
                $name,
                $description,
                $resources,
                $placementConstraints,
                $stockLimit,
                $perCustomerLimit,
                $isActive,
                $isPublic,
                $sortOrder,
            ): Plan {
                $existing = Plan::query()
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'product_id' => $product->getKey(),
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'resources' => $resources,
                    'placement_constraints' => $placementConstraints,
                    'stock_limit' => $stockLimit,
                    'per_customer_limit' => $perCustomerLimit,
                    'is_active' => $isActive,
                    'is_public' => $isPublic,
                    'sort_order' => $sortOrder,
                ];

                if ($existing === null) {
                    return Plan::query()->create($attributes);
                }

                $existing->fill($attributes);
                $existing->save();

                return $existing;
            }),
            describe: fn (Plan $plan): AuditedAct => new AuditedAct(
                action: AuditAction::CataloguePlanRecorded,
                subject: $plan,
                context: [
                    'slug' => $plan->slug,
                    'product' => $product->slug,
                    'kind' => $product->kind->value,
                    'is_active' => $plan->is_active,
                    'is_public' => $plan->is_public,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $resources
     *
     * @throws CatalogueRefused
     */
    private function assertResourcesSuit(ProductKind $kind, array $resources): void
    {
        if ($kind === ProductKind::Vps) {
            foreach (['vcpu', 'memory_mib', 'disk_gib'] as $key) {
                $value = $resources[$key] ?? null;

                if (! is_int($value) || $value < 1) {
                    throw CatalogueRefused::computePlanIsUnderspecified($key);
                }
            }

            return;
        }

        if ($kind === ProductKind::Dedicated) {
            $profile = $resources['hardware_profile'] ?? null;

            if (! is_string($profile) || trim($profile) === '') {
                throw CatalogueRefused::dedicatedPlanNamesNoHardwareProfile();
            }
        }
    }
}
