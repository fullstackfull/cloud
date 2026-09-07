<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\SharedHosting\Application\Actions\IssueHostingPanelSession;
use Lynomia\Modules\SharedHosting\Application\Queries\CustomerHostingAccounts;
use Lynomia\Modules\SharedHosting\Http\Requests\ListHostingAccountsRequest;
use Lynomia\Modules\SharedHosting\Http\Resources\HostingAccountResource;
use Lynomia\Modules\SharedHosting\Http\Resources\HostingAccountUsageResource;
use Lynomia\Modules\SharedHosting\Http\Resources\HostingPanelSessionResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * The customer-facing shared hosting surface.
 *
 * Three rules hold across every method here, and none of them is checked
 * twice.
 *
 * **Scoping, not checking.** Every account this controller touches is fetched
 * through `CustomerHostingAccounts::of($acting)`, which starts from the
 * customer. Another tenant's id matches no row and the request 404s — not 403,
 * because on ULIDs a 403 confirms the id names something real and turns the
 * endpoint into an enumeration oracle over the fleet. There is no
 * `HostingAccount::findOrFail()` followed by a comparison anywhere in this
 * class; a check that runs after an unscoped fetch has already had the other
 * tenant's username and domain in hand.
 *
 * **The panel is not on the request path, except for SSO.** Listing, showing
 * and usage read the platform's own record. A customer-triggered call to a
 * shared node is a customer-triggered load on the machine their neighbours are
 * running on, and it turns every busy node into a 502 on a page that only
 * wanted to show a domain name. SSO is the exception because a session must be
 * minted by the panel to exist at all.
 *
 * **Nothing here retries, and nothing here compensates.** A panel call that
 * times out means the platform stopped waiting, not that the panel stopped
 * working: {@see IssueHostingPanelSession} says so at length. The failure is
 * reported as it happened.
 */
final class HostingController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly IssueHostingPanelSession $panelSessions,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    /**
     * The acting customer's hosting accounts, newest first.
     */
    public function index(ListHostingAccountsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $status = $request->status();

        /** @var LengthAwarePaginator<int, HostingAccount> $accounts */
        $accounts = CustomerHostingAccounts::of($this->acting->get())
            ->with('package')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            // The ULID tie-breaks two accounts created in the same
            // millisecond, so paging is stable and a row cannot appear on two
            // pages while the customer walks the list.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => $accounts->getCollection()
                ->map(static fn (HostingAccount $account): HostingAccountResource => new HostingAccountResource($account))
                ->all(),
            'meta' => [
                'page' => $accounts->currentPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
                'last_page' => $accounts->lastPage(),
                'max_per_page' => ListHostingAccountsRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One account.
     */
    public function show(Request $request, string $account): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new HostingAccountResource($this->account($account)))->response();
    }

    /**
     * A one-time link into the control panel.
     *
     * `service.manage`, not `service.view`: the link is a logged-in panel
     * session, from which mail, databases, files and DNS can all be changed.
     * A member added to watch the account's services must not be handed one.
     *
     * 201, because a session now exists at the panel that did not exist
     * before. It is not idempotent and takes no idempotency key: each call
     * mints a fresh short-lived token, and returning a cached one would hand
     * the same live session to two browsers and let a spent link look like a
     * platform fault. The route carries a tighter limiter than the rest of the
     * group for that reason.
     */
    public function sso(Request $request, string $account): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $session = $this->panelSessions->execute($this->account($account));

        return (new HostingPanelSessionResource($session))->response()->setStatusCode(201);
    }

    /**
     * Disk and bandwidth against quota, as of the last sync.
     *
     * Read from the platform's record rather than from the node, and stamped
     * with when it was measured — see {@see HostingAccountUsageResource} for
     * why a figure without its timestamp is worse than no figure at all.
     */
    public function usage(Request $request, string $account): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new HostingAccountUsageResource($this->account($account)))->response();
    }

    /**
     * One of the acting customer's accounts, or a 404.
     *
     * The only way an account enters this class. Started from the customer, so
     * an id belonging to another tenant is not fetched-and-rejected, it is
     * simply not found.
     */
    private function account(string $id): HostingAccount
    {
        /** @var HostingAccount $account */
        $account = CustomerHostingAccounts::of($this->acting->get())
            ->with('package')
            ->whereKey($id)
            ->firstOrFail();

        return $account;
    }
}
