<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;

/**
 * An operator records a product family this platform sells.
 *
 * ===========================================================================
 * THE GAP THIS CLOSES
 * ===========================================================================
 *
 * E-11, found while doing 30B.0-E's hosting-package work: nothing could write
 * the catalogue in production. `Product`, `Plan`, `PlanPrice` and
 * `HostingPackage` had no writer anywhere in `src/` or `app/`. What wrote them
 * was `CatalogueSeeder`, which refuses to run in production — correctly,
 * because a seeder that invents prices will eventually invoice somebody for
 * them — and nothing was built to replace it.
 *
 * So a clean deployment could model everything it sells and sell none of it.
 * The only ways forward were raw SQL or a code change, which is the same rule
 * `vm_templates` was measured against in Gap 8: onboarding something this
 * platform already models must not require a business-code edit.
 *
 * ===========================================================================
 * THE KIND IS THE SOFTWARE, NOT A SETTING
 * ===========================================================================
 *
 * {@see ProductKind} has three cases, and an operator may not add a fourth.
 * That is the whole of the prepared-product safety in this action, and it is
 * structural rather than a check somebody remembered to write: WordPress and
 * Domains are `Prepared`, they have no kind, and there is therefore no request
 * body that creates a WordPress product. A kind with no provisioning path
 * behind it would be an order nobody can fulfil.
 *
 * Several products may share a kind — "Cloud VPS" and "Storage VPS" are both
 * `vps` — because the kind says how a thing is delivered and the product says
 * what is being sold. The slug is what is unique.
 *
 * ===========================================================================
 * AND IT STILL CANNOT MAKE ANYTHING SELLABLE
 * ===========================================================================
 *
 * Nothing here touches readiness. {@see ProductSellability}
 * reads the software state and the readiness row and has never read a
 * catalogue row, so a product recorded here is something an operator has
 * described, not something the platform has agreed it can deliver. Every
 * `REAL_*` claim is still NONE and this action does not move one.
 *
 * ===========================================================================
 * RECORDING THE SAME SLUG TWICE
 * ===========================================================================
 *
 * A correction, not a conflict — renaming a product, fixing a description,
 * re-listing one that was withdrawn. The row is locked rather than read,
 * because two operators editing the same product in the same minute would
 * otherwise both find it absent and race the unique index.
 */
final readonly class RecordProduct
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, string>  $name  Display names by locale.
     * @param  array<string, string>|null  $description  Descriptions by locale.
     *
     * @throws CatalogueRefused
     */
    public function execute(
        string $kind,
        string $slug,
        array $name,
        ?array $description,
        bool $isActive,
        bool $isPublic,
        int $sortOrder,
        User $operator,
    ): Product {
        $resolved = ProductKind::tryFrom($kind);

        if ($resolved === null) {
            throw CatalogueRefused::unknownProductKind(
                $kind,
                array_map(static fn (ProductKind $case): string => $case->value, ProductKind::cases()),
            );
        }

        return $this->record->execute(
            act: fn (): Product => DB::transaction(function () use (
                $resolved,
                $slug,
                $name,
                $description,
                $isActive,
                $isPublic,
                $sortOrder,
            ): Product {
                $existing = Product::query()
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'kind' => $resolved,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => $isActive,
                    'is_public' => $isPublic,
                    'sort_order' => $sortOrder,
                ];

                if ($existing === null) {
                    return Product::query()->create($attributes);
                }

                $existing->fill($attributes);
                $existing->save();

                return $existing;
            }),
            describe: fn (Product $product): AuditedAct => new AuditedAct(
                action: AuditAction::CatalogueProductRecorded,
                subject: $product,
                context: [
                    'slug' => $product->slug,
                    'kind' => $product->kind->value,
                    'is_active' => $product->is_active,
                    'is_public' => $product->is_public,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
