<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessAllProducts;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Application\Actions\DeclareProductSellable;
use Lynomia\Modules\ProductReadiness\Application\Actions\WithdrawProductSellability;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ReadinessRefused;
use Lynomia\Modules\ProductReadiness\Http\Requests\DeclareSellableRequest;
use Lynomia\Modules\ProductReadiness\Http\Requests\WithdrawSellabilityRequest;
use Lynomia\Modules\ProductReadiness\Http\Resources\ProductReadinessResource;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;
use Symfony\Component\HttpFoundation\Response;

final class ProductReadinessController
{
    /**
     * Every product, dependencies first.
     *
     * A product with no row yet has never been assessed — a fresh install —
     * and the honest list is the assessed one, so the sweep runs first in
     * that case rather than the screen showing an empty table.
     */
    public function index(Request $request, AssessAllProducts $assess): JsonResponse
    {
        if (ProductReadiness::query()->count() < count(Product::cases())) {
            $assess->execute();
        }

        $rows = ProductReadiness::query()->get()->keyBy(fn (ProductReadiness $row): string => $row->product->value);

        return response()->json([
            'data' => array_map(
                fn (Product $product): array => (new ProductReadinessResource($rows->get($product->value)))->toArray($request),
                Product::inDependencyOrder(),
            ),
        ]);
    }

    public function show(Request $request, string $product, AssessProduct $assess): JsonResponse
    {
        $which = Product::from($product);

        $row = ProductReadiness::query()->where('product', $which->value)->first();

        if ($row === null) {
            $assess->execute($which);
            $row = ProductReadiness::query()->where('product', $which->value)->firstOrFail();
        }

        return response()->json(['data' => (new ProductReadinessResource($row))->toArray($request)]);
    }

    public function assess(Request $request, string $product, AssessProduct $assess): JsonResponse
    {
        $which = Product::from($product);
        $assess->execute($which);

        $row = ProductReadiness::query()->where('product', $which->value)->firstOrFail();

        return response()->json(['data' => (new ProductReadinessResource($row))->toArray($request)]);
    }

    public function assessAll(AssessAllProducts $assess): JsonResponse
    {
        return response()->json(['data' => $assess->execute()]);
    }

    public function declareSellable(DeclareSellableRequest $request, string $product, DeclareProductSellable $declare): JsonResponse
    {
        try {
            $row = $declare->execute(
                Product::from($product),
                $request->user(),
                $request->string('reason')->value(),
                $request->string('validation_reference')->value(),
            );
        } catch (ReadinessRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => (new ProductReadinessResource($row))->toArray($request)]);
    }

    public function withdrawSellability(WithdrawSellabilityRequest $request, string $product, WithdrawProductSellability $withdraw): JsonResponse
    {
        try {
            $row = $withdraw->execute(Product::from($product), $request->user(), $request->string('reason')->value());
        } catch (ReadinessRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => (new ProductReadinessResource($row))->toArray($request)]);
    }

    /**
     * The dependency view: each product, what it leans on, and which provider
     * currently carries each requirement. Read from the assessed rows, so it
     * is the same evidence the ladder shows, arranged by edge rather than by
     * product.
     */
    public function dependencies(Request $request): JsonResponse
    {
        $rows = ProductReadiness::query()->get()->keyBy(fn (ProductReadiness $row): string => $row->product->value);

        $nodes = [];

        foreach (Product::inDependencyOrder() as $product) {
            /** @var ProductReadiness|null $row */
            $row = $rows->get($product->value);

            $nodes[] = [
                'product' => $product->value,
                'state' => $row?->state->value ?? 'not_ready',
                'depends_on' => array_map(static fn (Product $p): string => $p->value, $product->dependsOn()),
                'providers' => array_values(array_map(static fn (array $requirement): array => [
                    'category' => $requirement['category'],
                    'shared' => $requirement['shared'],
                    'provider_id' => $requirement['provider_id'],
                    'provider_name' => $requirement['provider_name'],
                    'satisfied_up_to' => $requirement['satisfied_up_to'],
                ], $row->requirements ?? [])),
            ];
        }

        return response()->json(['data' => $nodes]);
    }

    private function refused(ReadinessRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'readiness_refused', 'message' => $refusal->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
