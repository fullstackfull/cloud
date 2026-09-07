<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Billing\Application\Actions\CancelCustomerSubscription;
use Lynomia\Modules\Billing\Http\Requests\CancelSubscriptionRequest;
use Lynomia\Modules\Billing\Http\Requests\ListSubscriptionsRequest;
use Lynomia\Modules\Billing\Http\Resources\SubscriptionResource;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerSubscriptions;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The customer-facing subscription surface.
 *
 * The same two rules as the invoice surface: every subscription is fetched
 * through `CustomerSubscriptions::of($actingCustomer)` so an unscoped row is
 * never in hand, and another tenant's id gets a 404 rather than a 403 that
 * would confirm it exists.
 *
 * No transaction lives in this class. Cancelling is an action, so a queue
 * worker, a console command or an admin surface can perform exactly the same
 * operation without going through HTTP.
 */
final class SubscriptionController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly CancelCustomerSubscription $cancelSubscription,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The acting customer's subscriptions, newest first.
     */
    public function index(ListSubscriptionsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $status = $request->status();

        /** @var LengthAwarePaginator<int, Subscription> $subscriptions */
        $subscriptions = CustomerSubscriptions::of($this->actingCustomer->get())
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            // The ULID tie-breaks subscriptions created in the same
            // millisecond — two plans bought in one checkout — so paging is
            // stable and a row cannot appear on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => SubscriptionResource::collection($subscriptions->getCollection()),
            'meta' => [
                'page' => $subscriptions->currentPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
                'last_page' => $subscriptions->lastPage(),
                'max_per_page' => ListSubscriptionsRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    public function show(Request $request, string $subscription): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $found = CustomerSubscriptions::of($this->actingCustomer->get())
            ->whereKey($subscription)
            ->firstOrFail();

        return (new SubscriptionResource($found))->response();
    }

    /**
     * Ends a subscription, at the end of the paid period or immediately.
     *
     * `billing.pay` rather than `billing.view`, because a cancellation changes
     * what the account will be charged and when its service stops. The two
     * forms are the two the underlying action supports and they are genuinely
     * different events, so the response says which one happened rather than
     * leaving the client to infer it: a scheduled cancellation comes back
     * still `active` with `cancel_at` set and `is_scheduled_to_cancel` true,
     * an immediate one comes back `cancelled` with `ended_at` stamped.
     *
     * Nothing is refunded either way. Returning the unused remainder of a paid
     * period is a refund — a different operation, with different authorisation
     * — and issuing one from a cancel route would move money through an
     * endpoint nobody reviewed as one.
     *
     * The immediate form additionally carries `confirm_subscription_id`, which
     * must repeat the id in the path. It is irreversible — the state machine
     * has no edge back out of cancelled — and a boolean alone is not a
     * confirmation for something irreversible: it is a field generated clients
     * default and retry loops resend. The comparison itself belongs to
     * CancelCustomerSubscription, so a support script gets the same gate.
     */
    public function cancel(CancelSubscriptionRequest $request, string $subscription): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $found = CustomerSubscriptions::of($this->actingCustomer->get())
            ->whereKey($subscription)
            ->firstOrFail();

        $cancelled = $this->cancelSubscription->execute(
            $found,
            $request->immediately(),
            $request->confirmation(),
        );

        return (new SubscriptionResource($cancelled))->response();
    }
}
