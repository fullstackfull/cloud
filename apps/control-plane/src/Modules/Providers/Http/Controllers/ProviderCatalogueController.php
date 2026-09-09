<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Http\Resources\CatalogueEntryResource;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;

/**
 * What this build of Lynomia can be pointed at.
 *
 * Read-only and unpaginated: it is a short list from the source, not a table,
 * and paginating a dozen rows would only make the screen that consumes it more
 * complicated.
 *
 * Two facts per entry that a naive catalogue would not carry, and both are
 * about honesty rather than convenience. `testable` says whether a connection
 * tester exists for the driver — most have an adapter and no tester yet, and a
 * screen that hides that offers a button which cannot work. `available_here`
 * says whether it may be registered on this installation at all, which is how
 * the simulated driver disappears from a production control centre instead of
 * being offered and then refused.
 */
final class ProviderCatalogueController
{
    public function index(
        Request $request,
        ProviderCatalogue $catalogue,
        ConnectionTesterFactory $testers,
    ): JsonResponse {
        $controlled = $catalogue->controlledDrivers();
        $isProduction = app()->environment('production');

        $entries = array_map(
            fn (CatalogueEntry $entry): array => (new CatalogueEntryResource($entry))
                ->additional([
                    'testable' => $testers->handles($entry->driver),
                    'available_here' => ! ($isProduction && in_array($entry->driver, $controlled, true)),
                ])
                ->toArray($request),
            $catalogue->entries(),
        );

        return response()->json(['data' => $entries]);
    }
}
