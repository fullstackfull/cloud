<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Application\Actions\RequestDedicatedReinstall;
use Lynomia\Modules\Dedicated\Application\Queries\CustomerDedicatedServers;
use Lynomia\Modules\Dedicated\Http\Requests\ListDedicatedServersRequest;
use Lynomia\Modules\Dedicated\Http\Requests\PowerActionRequest;
use Lynomia\Modules\Dedicated\Http\Requests\ReinstallServerRequest;
use Lynomia\Modules\Dedicated\Http\Resources\DedicatedServerResource;
use Lynomia\Modules\Dedicated\Http\Resources\ReinstallRequestResource;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * The customer-facing dedicated-server surface.
 *
 * Four rules hold across every method here, and none of them is checked twice.
 *
 * **Scoping, not checking.** Every machine this controller touches is fetched
 * through `CustomerDedicatedServers::of($actingCustomer)`, so another tenant's
 * id simply matches no row and the request 404s. There is no
 * `where('customer_id')` at a call site to forget, and no
 * `abort_unless($server->customer_id === ...)` after the fact — an
 * authorisation check that runs after an unscoped fetch is a check that has
 * already had the row, its rack and its management endpoints in hand.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms the row exists. Answering identically for "no such server" and "not
 * your server" is what stops the API being an enumeration oracle over the
 * platform's entire physical estate. The within-account permission check
 * therefore runs *before* any lookup, so its 403 depends on the caller's role
 * and never on whether the id was real.
 *
 * **Nothing here decides anything about hardware.** Both write endpoints
 * delegate to an action, so an operator tool, a console command or a queue
 * worker reaching the same operation is held to the same refusals. No
 * transaction lives in this class and no BMC call is composed here.
 *
 * **A timeout is not a failure and is never retried.** The power action
 * translates an indeterminate controller response into its own exception, and
 * this controller does nothing with it but let it render — no second attempt,
 * no compensating power-off, no state written from a guess.
 */
final class DedicatedServerController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly ChangeDedicatedServerPower $changePower,
        private readonly RequestDedicatedReinstall $requestReinstall,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The acting customer's machines, newest first.
     */
    public function index(ListDedicatedServersRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var LengthAwarePaginator<int, DedicatedServer> $servers */
        $servers = CustomerDedicatedServers::of($this->actingCustomer->get())
            // The ULID tie-breaks machines delivered in the same millisecond —
            // two servers bought in one order — so paging is stable and a row
            // cannot appear on two pages.
            ->orderByDesc('dedicated_servers.created_at')
            ->orderByDesc('dedicated_servers.id')
            // The delivery date the resource publishes. Eager-loaded so that a
            // page of machines is two queries rather than one per row.
            ->with('service')
            // Bounded by the request, which clamps rather than refuses. The
            // clamp is what guarantees the query never sees the number a
            // caller asked for.
            ->paginate($request->perPage());

        return response()->json([
            'data' => DedicatedServerResource::collection($servers->getCollection()),
            'meta' => [
                'page' => $servers->currentPage(),
                'per_page' => $servers->perPage(),
                'total' => $servers->total(),
                'last_page' => $servers->lastPage(),
                'max_per_page' => ListDedicatedServersRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One machine, with the parts inside it.
     *
     * The components are loaded here and not in the list, because a customer
     * asking about one server wants to know whether a disk is failing, and a
     * customer listing five does not need every disk in all of them.
     */
    public function show(Request $request, string $server): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $found = $this->serverForActingCustomer($server);

        return (new DedicatedServerResource(
            $found->load([
                'components' => static fn ($query) => $query->orderBy('kind')->orderBy('id'),
                // Carries the delivery date. The chassis row's own created_at
                // is when the platform racked it, which is not what a customer
                // is asking when they ask how long they have had the machine.
                'service',
            ])
        ))->response();
    }

    /**
     * Power the machine on, ask it to shut down, or make it boot.
     *
     * 202 rather than 200. The controller has accepted the instruction; the
     * chassis takes seconds to act on it and an ACPI shutdown can take
     * minutes, so the machine in the response is the machine as the controller
     * last described it and not a promise about what it is doing now.
     */
    public function power(PowerActionRequest $request, string $server): JsonResponse
    {
        // Before the lookup, so a member without `service.manage` is refused
        // by their role rather than by whether they guessed a real id.
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->serverForActingCustomer($server);
        $action = $request->action();

        $operation = $this->changePower->execute($found, $action);

        return (new DedicatedServerResource($found))
            ->additional([
                'meta' => [
                    'action' => $action->value,
                    // What the controller said about the request, not what the
                    // platform hopes. The operation's own verb, its task handle
                    // and its provider metadata are deliberately not published:
                    // they are audit-log vocabulary, not a customer document.
                    'accepted' => $operation->accepted,
                ],
            ])
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Erase the machine and lay an operating system down on it again.
     *
     * 202, and the body is the recorded request rather than the machine: this
     * endpoint does not reinstall anything while the caller waits. It proves
     * the caller meant this machine, proves the machine is theirs and idle,
     * and records one job for the provisioning engine to carry out — which is
     * the only part of the platform that knows how not to retry a reinstall
     * that timed out.
     *
     * A repeated `Idempotency-Key` returns the job the first request created,
     * with the same id, rather than erasing the disks a second time.
     */
    public function reinstall(ReinstallServerRequest $request, string $server): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->serverForActingCustomer($server);

        $slug = $request->osProfileSlug();

        /*
         * Resolved by slug against the profiles that are actually installable,
         * and only after the machine has been resolved. The form request has
         * already refused a slug that names no active profile, so a null here
         * means the caller named nothing.
         */
        $profile = $slug === null
            ? null
            : OsInstallProfile::query()->active()->where('slug', $slug)->first();

        $job = $this->requestReinstall->execute(
            server: $found,
            confirmation: $request->confirmation(),
            idempotencyKey: $request->idempotencyKey(),
            profile: $profile,
        );

        return (new ReinstallRequestResource($job))
            ->additional([
                'meta' => [
                    'server_id' => (string) $found->getKey(),
                    /*
                     * Whether this request created the job or replayed one that
                     * already existed. Stated rather than expressed as a status
                     * code, because a client that retried after a dropped
                     * response deserves to know its retry was recognised — and
                     * because reading the key first to choose between 201 and
                     * 200 would put a second idempotency lookup in front of the
                     * one the unique constraint already performs.
                     */
                    'replayed' => ! $job->wasRecentlyCreated,
                ],
            ])
            ->response()
            ->setStatusCode(202);
    }

    /**
     * One machine, or a 404.
     *
     * The single place an id from a URL becomes a row, so there is one query
     * to get right rather than four. It is a relation hanging off the acting
     * customer, so a machine belonging to another account — or one that has
     * been wiped and returned to stock, which is no longer this customer's
     * either — is not found rather than found and then refused.
     */
    private function serverForActingCustomer(string $server): DedicatedServer
    {
        return CustomerDedicatedServers::of($this->actingCustomer->get())
            ->whereKey($server)
            ->firstOrFail();
    }
}
