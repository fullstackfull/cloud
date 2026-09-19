<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Billing\Domain\Exceptions\UnsupportedBillingCurrencyException;
use Lynomia\Modules\Catalog\Application\Actions\RecordPlan;
use Lynomia\Modules\Catalog\Application\Actions\RecordProduct;
use Lynomia\Modules\Catalog\Application\Actions\SetPlanPrice;
use Lynomia\Modules\Catalog\Application\Actions\WithdrawFromSale;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Exceptions\CatalogueRefused;
use Lynomia\Modules\Catalog\Http\Requests\RecordPlanRequest;
use Lynomia\Modules\Catalog\Http\Requests\RecordProductRequest;
use Lynomia\Modules\Catalog\Http\Requests\SetPlanPriceRequest;
use Lynomia\Modules\Catalog\Http\Resources\OperatorPlanResource;
use Lynomia\Modules\Catalog\Http\Resources\OperatorProductResource;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalogue an operator configures: what is sold, and for how much.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * E-11. Before this, `Product`, `Plan` and `PlanPrice` had no writer anywhere
 * in `src/` or `app/` — the only thing that wrote them was a seeder which
 * refuses to run in production — so a clean deployment could model everything
 * it sells and sell none of it, with raw SQL or a code change as the only
 * routes forward.
 *
 * ===========================================================================
 * RECORDING SOMETHING TWICE IS A CORRECTION
 * ===========================================================================
 *
 * Every write here is keyed by something stable — a product or plan slug, a
 * price's (plan, currency, period) — and a second call with the same key edits
 * the row rather than colliding with it. So the endpoints answer 200 on an
 * update and 201 on a create, and an operator fixing a typo has a route
 * through the API instead of a unique-constraint error for doing the thing the
 * endpoint is for.
 *
 * DELETE withdraws rather than destroys, because history points at these rows.
 * See {@see WithdrawFromSale}.
 */
final class OperatorCatalogueController
{
    public function products(Request $request): JsonResponse
    {
        $products = Product::query()
            ->withCount('plans')
            ->when(
                $request->filled('kind'),
                fn ($query) => $query->where('kind', $request->string('kind')->value()),
            )
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $products->map(
                fn (Product $product): array => (new OperatorProductResource($product))->toArray($request),
            )->all(),
            'meta' => [
                'total' => $products->count(),
                'on_sale' => $products->filter(
                    static fn (Product $product): bool => $product->is_active && $product->is_public,
                )->count(),
            ],
        ]);
    }

    public function recordProduct(RecordProductRequest $request, RecordProduct $record): JsonResponse
    {
        $existing = Product::query()->where('slug', $request->string('slug')->value())->exists();

        /** @var User $operator */
        $operator = $request->user();

        try {
            $product = $record->execute(
                kind: $request->string('kind')->value(),
                slug: $request->string('slug')->value(),
                name: $this->localised($request->array('name')),
                description: $this->optionalLocalised($request->array('description')),
                isActive: $request->boolean('is_active'),
                isPublic: $request->boolean('is_public'),
                sortOrder: (int) ($request->integer('sort_order') ?? 0),
                operator: $operator,
            );
        } catch (CatalogueRefused $refusal) {
            return $this->refused($refusal);
        }

        $product->loadCount('plans');

        return response()->json(
            ['data' => (new OperatorProductResource($product))->toArray($request)],
            $existing ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function withdrawProduct(Request $request, string $product, WithdrawFromSale $withdraw): JsonResponse
    {
        /** @var Product $found */
        $found = Product::query()->findOrFail($product);

        /** @var User $operator */
        $operator = $request->user();

        $withdraw->product($found, $operator);
        $found->loadCount('plans');

        return response()->json(['data' => (new OperatorProductResource($found))->toArray($request)]);
    }

    public function plans(Request $request): JsonResponse
    {
        $plans = Plan::query()
            ->with('prices')
            ->when(
                $request->filled('product'),
                fn ($query) => $query->where('product_id', $request->string('product')->value()),
            )
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $plans->map(
                fn (Plan $plan): array => (new OperatorPlanResource($plan))->toArray($request),
            )->all(),
            'meta' => [
                'total' => $plans->count(),
                /*
                 * A plan on sale with no active price is purchasable by
                 * nobody, and it is the configuration mistake this screen
                 * exists to make visible.
                 */
                'unpriced' => $plans->filter(
                    static fn (Plan $plan): bool => $plan->is_active
                        && $plan->prices->every(static fn (PlanPrice $price): bool => ! $price->is_active),
                )->count(),
            ],
        ]);
    }

    public function recordPlan(RecordPlanRequest $request, RecordPlan $record): JsonResponse
    {
        /** @var Product $product */
        $product = Product::query()->findOrFail($request->string('product_id')->value());

        $existing = Plan::query()->where('slug', $request->string('slug')->value())->exists();

        /** @var User $operator */
        $operator = $request->user();

        try {
            $plan = $record->execute(
                product: $product,
                slug: $request->string('slug')->value(),
                name: $this->localised($request->array('name')),
                description: $this->optionalLocalised($request->array('description')),
                resources: $request->array('resources'),
                placementConstraints: $request->has('placement_constraints') && $request->array('placement_constraints') !== []
                    ? $request->array('placement_constraints')
                    : null,
                stockLimit: $request->filled('stock_limit') ? $request->integer('stock_limit') : null,
                perCustomerLimit: $request->filled('per_customer_limit') ? $request->integer('per_customer_limit') : null,
                isActive: $request->boolean('is_active'),
                isPublic: $request->boolean('is_public'),
                sortOrder: (int) ($request->integer('sort_order') ?? 0),
                operator: $operator,
            );
        } catch (CatalogueRefused $refusal) {
            return $this->refused($refusal);
        }

        $plan->load('prices');

        return response()->json(
            ['data' => (new OperatorPlanResource($plan))->toArray($request)],
            $existing ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function withdrawPlan(Request $request, string $plan, WithdrawFromSale $withdraw): JsonResponse
    {
        /** @var Plan $found */
        $found = Plan::query()->findOrFail($plan);

        /** @var User $operator */
        $operator = $request->user();

        $withdraw->plan($found, $operator);
        $found->load('prices');

        return response()->json(['data' => (new OperatorPlanResource($found))->toArray($request)]);
    }

    public function setPrice(SetPlanPriceRequest $request, string $plan, SetPlanPrice $set): JsonResponse
    {
        /** @var Plan $found */
        $found = Plan::query()->findOrFail($plan);

        $currency = strtoupper($request->string('currency')->value());
        $period = BillingPeriod::from($request->string('billing_period')->value());

        $existing = PlanPrice::query()
            ->where('plan_id', $found->getKey())
            ->where('currency', $currency)
            ->where('billing_period', $period->value)
            ->exists();

        /** @var User $operator */
        $operator = $request->user();

        try {
            $set->execute(
                plan: $found,
                currency: $currency,
                period: $period,
                recurringMinor: (int) $request->integer('recurring_amount_minor'),
                setupMinor: (int) ($request->integer('setup_amount_minor') ?? 0),
                isActive: $request->boolean('is_active'),
                availableFrom: $request->filled('available_from')
                    ? CarbonImmutable::parse($request->string('available_from')->value())
                    : null,
                availableUntil: $request->filled('available_until')
                    ? CarbonImmutable::parse($request->string('available_until')->value())
                    : null,
                operator: $operator,
            );
        } catch (CatalogueRefused $refusal) {
            return $this->refused($refusal);
        } catch (UnsupportedBillingCurrencyException $refusal) {
            return response()->json(
                ['error' => ['code' => 'catalogue_refused', 'message' => $refusal->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        $found->load('prices');

        return response()->json(
            ['data' => (new OperatorPlanResource($found))->toArray($request)],
            $existing ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function withdrawPrice(Request $request, string $plan, string $price, WithdrawFromSale $withdraw): JsonResponse
    {
        /** @var PlanPrice $found */
        $found = PlanPrice::query()
            ->where('plan_id', $plan)
            ->where('id', $price)
            ->firstOrFail();

        /** @var User $operator */
        $operator = $request->user();

        $withdraw->price($found, $operator);

        /** @var Plan $owner */
        $owner = Plan::query()->findOrFail($plan);
        $owner->load('prices');

        return response()->json(['data' => (new OperatorPlanResource($owner))->toArray($request)]);
    }

    /**
     * @param  array<array-key, mixed>  $given
     * @return array<string, string>
     */
    private function localised(array $given): array
    {
        $names = [];

        foreach ($given as $locale => $value) {
            $names[(string) $locale] = trim((string) $value);
        }

        return $names;
    }

    /**
     * @param  array<array-key, mixed>  $given
     * @return array<string, string>|null
     */
    private function optionalLocalised(array $given): ?array
    {
        $values = array_filter(
            $this->localised($given),
            static fn (string $value): bool => $value !== '',
        );

        return $values === [] ? null : $values;
    }

    private function refused(CatalogueRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'catalogue_refused', 'message' => $refusal->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
