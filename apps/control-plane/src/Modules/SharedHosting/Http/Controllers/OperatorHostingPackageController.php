<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Application\Actions\MapHostingPackage;
use Lynomia\Modules\SharedHosting\Http\Requests\MapHostingPackageRequest;
use Lynomia\Modules\SharedHosting\Http\Resources\OperatorHostingPackageResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The packages a shared hosting plan is delivered as, as operator data.
 *
 * The preflight has reported `mapping.hosting_package` as failed since it was
 * written — "an account has no plan to be created under" — and until now there
 * was nothing an operator could do about it. This is that thing.
 *
 * What it records is an intention: the name this platform will ask a panel
 * for. Whether a package by that name exists on the node is real provider
 * discovery's question, and it has not run. A mapping here is CONFIGURED and
 * never VERIFIED.
 */
final class OperatorHostingPackageController
{
    /** @var list<string> */
    private const array LIMITS = [
        'disk_quota_mib',
        'bandwidth_quota_mib',
        'max_addon_domains',
        'max_subdomains',
        'max_databases',
        'max_email_accounts',
        'cpu_limit_percent',
        'memory_limit_mib',
        'io_limit_kbps',
        'process_limit',
        'entry_process_limit',
    ];

    public function index(Request $request): JsonResponse
    {
        $packages = HostingPackage::query()
            ->when(
                $request->filled('plan'),
                fn ($query) => $query->where('plan_id', $request->string('plan')->value()),
            )
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $packages->map(
                fn (HostingPackage $package): array => (new OperatorHostingPackageResource($package))->toArray($request),
            )->all(),
            'meta' => [
                'total' => $packages->count(),
                /*
                 * The number the preflight's mapping check is really asking
                 * about: a package nobody can order under is a package mapped
                 * to no plan, or switched off.
                 */
                'orderable' => $packages->filter(
                    static fn (HostingPackage $package): bool => $package->is_active && $package->plan_id !== null,
                )->count(),
            ],
        ]);
    }

    public function store(MapHostingPackageRequest $request, MapHostingPackage $map): JsonResponse
    {
        $existing = HostingPackage::query()->where('slug', $request->string('slug')->value())->exists();

        $planId = $request->filled('plan_id') ? $request->string('plan_id')->value() : null;
        $planKind = null;

        if ($planId !== null) {
            /*
             * Catalog's Plan model, directly, which is the boundary this
             * codebase actually keeps: a module may use another module's
             * models and may never reach its HTTP layer. `HostingPackage`
             * already belongs to a Plan for the same reason.
             */
            $plan = Plan::query()->with('product')->find($planId);
            $planKind = $plan?->product?->kind;
        }

        /** @var User $operator */
        $operator = $request->user();

        $limits = [];

        foreach (self::LIMITS as $limit) {
            $limits[$limit] = $request->filled($limit) ? $request->integer($limit) : null;
        }

        try {
            $package = $map->execute(
                slug: $request->string('slug')->value(),
                panelPackageName: $request->string('panel_package_name')->value(),
                planId: $planId,
                planKind: $planKind,
                limits: $limits,
                isActive: $request->boolean('is_active'),
                operator: $operator,
            );
        } catch (CatalogueRefused $refusal) {
            return response()->json([
                'error' => ['code' => 'catalogue_refused', 'message' => $refusal->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(
            ['data' => (new OperatorHostingPackageResource($package))->toArray($request)],
            $existing ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, string $package, MapHostingPackage $map): JsonResponse
    {
        /** @var HostingPackage $found */
        $found = HostingPackage::query()->findOrFail($package);

        /** @var User $operator */
        $operator = $request->user();

        $map->withdraw($found, $operator);

        return response()->json(['data' => (new OperatorHostingPackageResource($found))->toArray($request)]);
    }
}
