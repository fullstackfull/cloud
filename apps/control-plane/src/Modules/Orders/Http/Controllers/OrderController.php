<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Actions\CancelOrder;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Domain\Exceptions\AccountPermissionRequiredException;
use Lynomia\Modules\Orders\Http\Requests\CancelOrderRequest;
use Lynomia\Modules\Orders\Http\Requests\ListOrdersRequest;
use Lynomia\Modules\Orders\Http\Requests\PlaceOrderRequest;
use Lynomia\Modules\Orders\Http\Resources\OrderResource;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Queries\CustomerOrders;

/**
 * The customer-facing order surface.
 *
 * Two rules hold across every method here, and neither is checked twice.
 *
 * **Scoping, not checking.** Every order this controller touches is fetched
 * through `CustomerOrders::of($actingCustomer)`, so another tenant's id simply
 * matches no row and the request 404s. There is no `where('customer_id')` at a
 * call site to forget, and no `abort_unless($order->customer_id === ...)` after
 * the fact — an authorisation check that runs *after* an unscoped fetch is a
 * check that has already had the row in hand.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms that the row exists. Answering identically for "no such order" and
 * "not your order" is what stops the API being an enumeration oracle. The
 * within-account permission check therefore runs *before* any lookup, so its
 * 403 depends on the caller's role and never on whether the id was real.
 *
 * No transaction lives in this class. Placing and cancelling are actions, so a
 * queue worker, a console command or an admin surface can perform the same
 * operation without going through HTTP.
 */
final class OrderController
{
    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly PlaceOrder $placeOrder,
        private readonly CancelOrder $cancelOrder,
    ) {}

    /**
     * The acting customer's orders, newest first.
     */
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $status = $request->status();

        /** @var LengthAwarePaginator<int, Order> $orders */
        $orders = CustomerOrders::of($this->acting->get())
            // Counted, not loaded: a list of twenty-five orders does not need
            // every line of every one of them, and GET /orders/{order} is where
            // the lines live.
            ->withCount('items')
            ->with('coupon')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            // The ULID tie-breaks orders created in the same millisecond, so
            // paging is stable and a row cannot appear on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => OrderResource::collection($orders->getCollection()),
            'meta' => [
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
                'max_per_page' => ListOrdersRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * Checkout.
     *
     * Everything about the money is decided by PlaceOrder from the catalogue,
     * the account's currency and its billing address. This method contributes
     * plan ids, quantities, a period, a coupon code and an idempotency key.
     *
     * Always 201, including for a repeat of a key that has already been used.
     * The alternative — reading the key first to decide between 200 and 201 —
     * would put a second idempotency lookup in front of the one PlaceOrder
     * already does under the unique constraint, and the status code is not
     * worth a second mechanism that can disagree with the first.
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $user = $request->user();

        $order = $this->placeOrder->execute(
            $this->acting->get(),
            $request->toCheckoutRequest(),
            $user instanceof User ? $user : null,
        );

        return (new OrderResource($order->load(['items', 'coupon'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * One order and its lines.
     */
    public function show(Request $request, string $order): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $found = CustomerOrders::of($this->acting->get())
            ->with(['items', 'coupon'])
            ->whereKey($order)
            ->firstOrFail();

        return (new OrderResource($found))->response();
    }

    /**
     * Withdraws an order that has not been paid.
     *
     * A paid order is refused with `order.already_paid` rather than refunded:
     * moving money back is a different operation with different authorisation,
     * and quietly doing it under a "cancel" route would let a customer trigger a
     * refund from an endpoint nobody reviewed as one.
     */
    public function cancel(CancelOrderRequest $request, string $order): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $found = CustomerOrders::of($this->acting->get())
            ->whereKey($order)
            ->firstOrFail();

        $user = $request->user();

        $cancelled = $this->cancelOrder->execute(
            $found,
            $user instanceof User ? $user : null,
            $request->reason(),
        );

        return (new OrderResource($cancelled->load(['items', 'coupon'])))->response();
    }

    /**
     * The caller's authority *inside* the account they are acting for.
     *
     * Membership alone is not permission to spend: the roles carry explicit
     * permissions, and someone added to an account to watch its services
     * (`member`) has no business placing an order against its payment methods.
     * Reading is `billing.view`; placing and cancelling are `billing.pay`,
     * because a cancellation moves an order the account is on the hook for.
     *
     * Deliberately the first thing every method does. Run after the lookup, its
     * 403 would tell an unauthorised caller which ids exist.
     *
     * @throws AccountPermissionRequiredException
     */
    private function authoriseWithinAccount(Request $request, string $permission): void
    {
        $user = $request->user();
        $role = $user instanceof User ? $user->roleWithin($this->acting->id()) : null;

        if ($role === null || ! $role->can($permission)) {
            throw AccountPermissionRequiredException::forPermission($permission);
        }
    }
}
