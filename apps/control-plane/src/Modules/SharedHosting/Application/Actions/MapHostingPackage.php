<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * An operator maps a plan onto the package a control panel will create under.
 *
 * ===========================================================================
 * THE ROW THE PREFLIGHT WAS ALREADY ASKING FOR
 * ===========================================================================
 *
 * `mapping.hosting_package` has been a **FAIL** in the infrastructure
 * preflight from the day it was written — "No hosting package is mapped, so an
 * account has no plan to be created under" — and there was no way to make it
 * pass. `CreateHostingAccountHandler` reads `hosting_package_id` and refuses
 * when the package does not exist, so a real panel onboarded through the
 * Control Center would have had zero packages and every Shared Hosting order
 * would have been refused, with raw SQL or a code change as the only routes
 * forward. That is E-11 at its narrowest point.
 *
 * ===========================================================================
 * CONFIGURED IS NOT VERIFIED
 * ===========================================================================
 *
 * `panel_package_name` is what this platform will ask cPanel or DirectAdmin
 * for. Nothing here contacts a panel, and nothing here should: the operator is
 * recording an intention, and whether a package by that name exists on the
 * node is a question for real provider discovery, which has not run — 30B.0-E
 * is blocked and `REAL_HOSTING_VERIFIED` is NONE.
 *
 * So a mapping recorded here is CONFIGURED. It is not evidence that the
 * package exists, and this action must never be read as making it one. The
 * same distinction the provider rows already keep: a row is what somebody
 * said, an identity test is what a product said about itself.
 *
 * ===========================================================================
 * ONLY ONTO A HOSTING PLAN
 * ===========================================================================
 *
 * A package mapped onto a VPS plan would put a panel package behind an order
 * the panel never sees — the build would go to a hypervisor and the mapping
 * would sit there looking configured. The plan's product kind is read rather
 * than trusted, and anything but shared hosting is refused.
 *
 * The plan is nullable in the schema and stays so: a package recorded before
 * its plan exists is a half-finished configuration an operator can see and
 * finish, which is better than refusing the first of two steps because the
 * second has not happened.
 */
final readonly class MapHostingPackage
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, int|null>  $limits  Quota and CloudLinux limits, by column name.
     *
     * @throws CatalogueRefused
     */
    public function execute(
        string $slug,
        string $panelPackageName,
        ?string $planId,
        ?ProductKind $planKind,
        array $limits,
        bool $isActive,
        User $operator,
    ): HostingPackage {
        if ($planId !== null && $planKind !== ProductKind::SharedHosting) {
            throw CatalogueRefused::hostingPackageIsNotForHosting(
                $planId,
                $planKind ?? ProductKind::Vps,
            );
        }

        return $this->record->execute(
            act: fn (): HostingPackage => DB::transaction(function () use (
                $slug,
                $panelPackageName,
                $planId,
                $limits,
                $isActive,
            ): HostingPackage {
                $existing = HostingPackage::query()
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'slug' => $slug,
                    'panel_package_name' => $panelPackageName,
                    'plan_id' => $planId,
                    'is_active' => $isActive,
                ] + $limits;

                if ($existing === null) {
                    return HostingPackage::query()->create($attributes);
                }

                $existing->fill($attributes);
                $existing->save();

                return $existing;
            }),
            describe: fn (HostingPackage $package): AuditedAct => new AuditedAct(
                action: AuditAction::CatalogueHostingPackageMapped,
                subject: $package,
                context: [
                    'slug' => $package->slug,
                    'panel_package_name' => $package->panel_package_name,
                    'plan_id' => $package->plan_id === null ? null : (string) $package->plan_id,
                    'is_active' => $package->is_active,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }

    public function withdraw(HostingPackage $package, User $operator): HostingPackage
    {
        return $this->record->execute(
            act: function () use ($package): HostingPackage {
                DB::transaction(function () use ($package): void {
                    $package->forceFill(['is_active' => false])->save();
                });

                return $package;
            },
            describe: fn (HostingPackage $saved): AuditedAct => new AuditedAct(
                action: AuditAction::CatalogueHostingPackageWithdrawn,
                subject: $saved,
                context: [
                    'slug' => $saved->slug,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
