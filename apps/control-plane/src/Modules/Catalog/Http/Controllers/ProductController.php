<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Catalog\Application\Actions\FindPurchasableProduct;
use Lynomia\Modules\Catalog\Application\Actions\ListPurchasableProducts;
use Lynomia\Modules\Catalog\Http\Requests\ListProductsRequest;
use Lynomia\Modules\Catalog\Http\Resources\ProductResource;
use Lynomia\Modules\Catalog\Http\Support\RequestLocale;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * Browsing what the platform sells.
 *
 * Read-only, and the same catalogue for everyone — but not the same response:
 * prices are quoted in the acting customer's currency, and that currency is
 * read from the account the middleware resolved, never from the request.
 */
final readonly class ProductController
{
    public function __construct(
        private ActingCustomer $acting,
        private ListPurchasableProducts $list,
        private FindPurchasableProduct $find,
    ) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $customer = $this->acting->get();

        // The ceiling is applied by clamping rather than by a validation
        // rule, so an over-large page size is served bounded instead of
        // refused. It is not the guarantee, though: the action applies the
        // same bound to every caller, HTTP or not.
        $products = $this->list->execute($request->kind(), $request->perPage());

        return ProductResource::collection($products->getCollection())
            ->additional(['meta' => [
                // The same envelope every other collection on this API uses —
                // invoices, orders, payments, services, machines, tokens. A
                // client's paging component is written once.
                'page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'max_per_page' => ListProductsRequest::MAX_PER_PAGE,

                // Both are decisions the response depends on, so a client can
                // see which currency it was quoted in and which language it
                // was answered in rather than inferring them.
                'currency' => strtoupper($customer->currency),
                'locale' => RequestLocale::for($request),
            ]])
            ->response();
    }

    /**
     * @param  string  $product  slug or id — both resolve through the same
     *                           visibility rules, and neither identifies a
     *                           tenant, so there is nothing here to scope
     */
    public function show(Request $request, string $product): JsonResponse
    {
        $customer = $this->acting->get();

        $found = $this->find->execute($product, $customer->currency);

        return ProductResource::make($found)
            ->additional(['meta' => [
                'currency' => strtoupper($customer->currency),
                'locale' => RequestLocale::for($request),
            ]])
            ->response();
    }
}
