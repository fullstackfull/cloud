<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Provisioning\Application\Queries\CustomerServices;
use Lynomia\Modules\Provisioning\Http\Requests\ListServicesRequest;
use Lynomia\Modules\Provisioning\Http\Resources\ServiceResource;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * The customer-facing service surface. Read-only, and deliberately so.
 *
 * This is the index across every kind of thing a customer can buy: a VPS, a
 * dedicated server and a hosting account are all one row here, which is what
 * lets a portal show "your services" without knowing what a hypervisor is.
 * Acting on any of them — rebooting, reinstalling, resizing, cancelling — is
 * the fulfilling module's surface, because each of those operations spends
 * money or provisions hardware and needs its own idempotency key and its own
 * authorisation. Nothing here writes, so nothing here needs one.
 *
 * Two rules hold across both methods, and neither is checked twice.
 *
 * **Scoping, not checking.** Every service is fetched through
 * `CustomerServices::of($actingCustomer)`, so another tenant's id matches no
 * row. There is no `where('customer_id')` at a call site to forget, and no
 * `abort_unless($service->customer_id === ...)` afterwards — a check that runs
 * after an unscoped fetch has already had the row.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms the row exists. Answering identically for "no such service" and
 * "not your service" is what stops the API being an enumeration oracle over
 * the platform's entire estate.
 */
final class ServiceController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly ServiceIdentities $identities,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The acting customer's services, newest first.
     */
    public function index(ListServicesRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $query = CustomerServices::of($this->actingCustomer->get());

        if (($state = $request->state()) !== null) {
            CustomerServices::inState($query, $state);
        }

        if (($kind = $request->kind()) !== null) {
            $query->where('services.kind', $kind->value);
        }

        /** @var LengthAwarePaginator<int, Service> $services */
        $services = $query
            // The ULID tie-breaks services created in the same millisecond —
            // two plans bought in one checkout — so paging is stable and a row
            // cannot appear on two pages.
            ->orderByDesc('services.created_at')
            ->orderByDesc('services.id')
            ->paginate($request->perPage());

        /*
         * The hostnames, domains and serials for the whole page, in one query
         * per kind. Without this the index is a list of catalogue labels, and
         * two servers on the same plan are two identical rows — which is what
         * the audit found and what made the index unusable as an ownership
         * list.
         */
        $this->identities->attach($services->getCollection());

        return response()->json([
            'data' => ServiceResource::collection($services->getCollection()),
            'meta' => [
                'page' => $services->currentPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
                'last_page' => $services->lastPage(),
                'max_per_page' => ListServicesRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One service, and where it currently stands.
     *
     * The state is derived the same way it is in the list — from the service
     * row and the most recent provisioning job — because a detail view that
     * computed it differently would be a second implementation of the one
     * thing this endpoint exists to say.
     */
    public function show(Request $request, string $service): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $found = CustomerServices::of($this->actingCustomer->get())
            ->whereKey($service)
            ->firstOrFail();

        $this->identities->attach([$found]);

        return (new ServiceResource($found))->response();
    }
}
