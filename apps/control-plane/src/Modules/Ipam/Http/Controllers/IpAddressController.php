<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Ipam\Application\Actions\SetReverseDns;
use Lynomia\Modules\Ipam\Application\Queries\CustomerIpAssignments;
use Lynomia\Modules\Ipam\Http\Requests\ListIpAssignmentsRequest;
use Lynomia\Modules\Ipam\Http\Requests\SetReverseDnsRequest;
use Lynomia\Modules\Ipam\Http\Resources\IpAssignmentResource;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The customer-facing address surface.
 *
 * Four rules hold across every method, and none of them is checked twice.
 *
 * **Scoping, not checking.** Every row this controller touches is fetched
 * through `CustomerIpAssignments::of($actingCustomer)`, so another tenant's id
 * matches no row and the request 404s. There is no `where('customer_id')` at a
 * call site to forget and no `abort_unless($assignment->customer_id === ...)`
 * afterwards — a check that runs after an unscoped fetch has already had the
 * row, its subnet and its pool in hand.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms the row exists. The within-account permission check therefore runs
 * *before* any lookup, so its 403 depends on the caller's role and never on
 * whether the id was real.
 *
 * **Only what is assigned, and only now.** The scope excludes released
 * assignments, so this surface can show neither the pool, nor the other
 * addresses in the customer's subnet, nor an address the customer used to hold
 * and that somebody else may be answering on today. There is deliberately no
 * endpoint for a subnet, a pool, a network or the quarantine list: those are
 * the platform's inventory, not a customer's.
 *
 * **The DNS provider is never called from here.** The rDNS endpoint records
 * what the customer asked for and returns 202; a worker publishes it. A zone
 * API call inside the request would hang the customer's browser on a third
 * party, and a call that timed out would leave the platform with an answer it
 * must not guess at.
 */
final class IpAddressController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly SetReverseDns $setReverseDns,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The addresses currently assigned to the acting customer.
     *
     * Primary addresses first, then most recently assigned: a customer opening
     * this page is looking for the address their server answers on, and it is
     * the one they are least likely to be able to pick out of a list.
     */
    public function index(ListIpAssignmentsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var LengthAwarePaginator<int, IpAssignment> $assignments */
        $assignments = CustomerIpAssignments::of($this->actingCustomer->get())
            // Loaded up front rather than per row: the resource needs the
            // address, the subnet it sits in and any PTR, and lazy loading is
            // disabled outside production precisely so this is not discovered
            // as twenty-five extra queries in production.
            ->with(['ipAddress.subnet', 'ipAddress.reverseDnsRecord'])
            ->orderByDesc('ip_assignments.is_primary')
            ->orderByDesc('ip_assignments.assigned_at')
            // The ULID tie-breaks addresses assigned in the same millisecond —
            // a machine built with four of them — so paging is stable and a row
            // cannot appear on two pages.
            ->orderByDesc('ip_assignments.id')
            // Bounded by the request, which clamps rather than refuses. The
            // clamp is what guarantees the query never sees the number a caller
            // asked for.
            ->paginate($request->perPage());

        return response()->json([
            'data' => IpAssignmentResource::collection($assignments->getCollection()),
            'meta' => [
                'page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
                'max_per_page' => ListIpAssignmentsRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One address the acting customer holds.
     */
    public function show(Request $request, string $assignment): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new IpAssignmentResource($this->assignmentForActingCustomer($assignment)))->response();
    }

    /**
     * Set the reverse DNS for one address.
     *
     * 202, not 200. The record has been accepted and stored; publishing it is a
     * separate call to a third party that has not happened yet, and a 200 with
     * the customer's hostname in it would read as "this is live now" — which is
     * exactly the thing the platform cannot promise while the zone API has not
     * been asked.
     *
     * Repeating the request is safe and is not gated by an idempotency key: one
     * address has one PTR row, held by a unique index, and sending the same
     * hostname twice converges on the same single record rather than creating a
     * second one.
     */
    public function setReverseDns(SetReverseDnsRequest $request, string $assignment): JsonResponse
    {
        // Before the lookup, so a member without `service.manage` is refused by
        // their role rather than by whether they guessed a real id.
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->assignmentForActingCustomer($assignment);

        $record = $this->setReverseDns->execute($found, $request->hostname());

        // Re-read through the same scoped relation so the response is built
        // from what is now stored, including the record just written.
        $fresh = $this->assignmentForActingCustomer($assignment);

        return (new IpAssignmentResource($fresh))
            ->additional([
                'meta' => [
                    /*
                     * Stated rather than implied by the status code: the record
                     * is accepted, and the customer is told plainly that it is
                     * not live until the platform has published it. A client
                     * polls GET /ips/{assignment} to see it settle.
                     */
                    'reverse_dns_status' => $record->status->value,
                    'published' => false,
                ],
            ])
            ->response()
            ->setStatusCode(202);
    }

    /**
     * One assignment, or a 404.
     *
     * The single place an id from a URL becomes a row, so there is one query to
     * get right rather than three. It hangs off the acting customer and
     * excludes released rows, so another account's address — or one this
     * customer gave back last month and which somebody else now holds — is not
     * found rather than found and then refused.
     */
    private function assignmentForActingCustomer(string $assignment): IpAssignment
    {
        return CustomerIpAssignments::of($this->actingCustomer->get())
            ->with(['ipAddress.subnet', 'ipAddress.reverseDnsRecord'])
            ->whereKey($assignment)
            ->firstOrFail();
    }
}
