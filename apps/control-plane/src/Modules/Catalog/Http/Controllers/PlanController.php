<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Catalog\Application\Actions\FindPurchasablePlan;
use Lynomia\Modules\Catalog\Http\Resources\PlanResource;
use Lynomia\Modules\Catalog\Http\Support\RequestLocale;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * One plan and its prices.
 *
 * A plan that is inactive, unlisted, or hanging off a product that is either
 * of those is a 404 and not a 403. The plan's existence is not a customer's
 * business, and on ULID identifiers the difference between the two statuses is
 * itself an oracle.
 */
final readonly class PlanController
{
    public function __construct(
        private ActingCustomer $acting,
        private FindPurchasablePlan $find,
    ) {}

    public function show(Request $request, string $plan): JsonResponse
    {
        $customer = $this->acting->get();

        $found = $this->find->execute($plan, $customer->currency);

        return PlanResource::make($found)
            ->additional(['meta' => [
                'currency' => strtoupper($customer->currency),
                'locale' => RequestLocale::for($request),
            ]])
            ->response();
    }
}
