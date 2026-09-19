<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Activity\Application\Queries\CustomerActivity;
use Lynomia\Modules\Activity\Http\Requests\ListActivityRequest;
use Lynomia\Modules\Activity\Http\Resources\ActivityItemResource;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * What has happened on this account.
 *
 * The scope is the acting customer and nothing else: the query takes the
 * customer and every branch inside it constrains on that customer's own id.
 * There is no id in the path and no id in the query string, so there is no
 * parameter here that could name another tenant's history — which is the
 * shape §49 asks for, because a feed that joins nine modules is the endpoint
 * most likely to become a data leak.
 *
 * `service.view` is the permission, the same one the per-resource history
 * uses. A member who may look at the account's services may read what happened
 * to them; a login with no permission at all reads nothing.
 */
final class ActivityController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly CustomerActivity $activity,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * One page of history, newest first.
     *
     * An account with no history returns an empty page rather than a 404: "you
     * have not done anything yet" is a fact about a new account, and the portal
     * has an empty state that says so usefully.
     */
    public function index(ListActivityRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $page = $this->activity->page(
            $this->actingCustomer->get(),
            $request->category(),
            $request->cursor(),
            $request->perPage(),
        );

        return response()->json([
            'data' => ActivityItemResource::collection($page->items),
            'meta' => [
                /*
                 * Cursor rather than page numbers, and no total. History grows
                 * at the newest end, so a customer reading page three while a
                 * backup completes would otherwise see one row twice and miss
                 * another; and counting eleven branches to print a number
                 * nothing uses would double the cost of every page.
                 */
                'next_cursor' => $page->nextCursor,
                'per_page' => $request->perPage(),
            ],
        ]);
    }
}
