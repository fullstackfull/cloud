<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\CancelCustomerSubscription;
use Lynomia\Modules\Billing\Http\Requests\CancelSubscriptionRequest;
use Lynomia\Modules\Billing\Http\Requests\ChangePlanRequest;
use Lynomia\Modules\Billing\Http\Requests\ListSubscriptionsRequest;
use Lynomia\Modules\Billing\Http\Resources\PlanChangeQuoteResource;
use Lynomia\Modules\Billing\Http\Resources\SubscriptionResource;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerSubscriptions;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\QuotePlanChange;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeQuote;
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
        private readonly QuotePlanChange $quotePlanChange,
        private readonly ApplyPlanChange $applyPlanChange,
        private readonly ServiceIdentities $identities,
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
            // Loaded so that every row can say what it is for: the plan the
            // agreement is on, the product that plan belongs to, and the
            // services it pays for. Without them two identical subscriptions
            // are two identical rows, and cancelling one is a guess.
            ->with(['plan.product', 'services'])
            // The ULID tie-breaks subscriptions created in the same
            // millisecond — two plans bought in one checkout — so paging is
            // stable and a row cannot appear on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        // One resolution for the whole page: four queries, not four per row.
        $this->identities->attach(
            $subscriptions->getCollection()->flatMap(static fn (Subscription $s) => $s->services),
        );

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
            ->with(['plan.product', 'services'])
            ->whereKey($subscription)
            ->firstOrFail();

        $this->identities->attach($found->services);

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

        /*
         * Recorded with the cancellation itself. A customer ringing to say
         * they never cancelled, or a dispute about when a service was stopped,
         * is settled from this row — and both are common enough that a
         * cancellation with no trail is a support case nobody can close.
         */
        $cancelled = app(RecordActAtomically::class)->execute(
            act: fn (): Subscription => $this->cancelSubscription->execute(
                $found,
                $request->immediately(),
                $request->confirmation(),
            ),
            describe: static fn (Subscription $subscription): AuditedAct => new AuditedAct(
                action: AuditAction::SubscriptionCancelled,
                subject: $subscription,
                customerId: (string) $subscription->customer_id,
                context: [
                    'immediately' => $request->immediately(),
                    'status' => $subscription->status->value,
                    'ends_at' => $subscription->current_period_end?->toIso8601String(),
                ],
            ),
        );

        return (new SubscriptionResource($cancelled))->response();
    }

    /**
     * Move a running subscription onto another plan.
     *
     * ChangeSubscriptionPlan has always been able to do this — crediting the
     * unused remainder of the plan being left and charging the same remainder
     * at the new one, through the same proration call so that an upgrade and
     * an immediate downgrade net to exactly zero — and no route reached it. A
     * customer who outgrew their plan had one option: cancel, and buy again.
     *
     * The billing anniversary does not move. A plan change is not a renewal,
     * and the next invoice arrives when it always would have.
     */
    public function changePlan(ChangePlanRequest $request, string $subscription): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $found = CustomerSubscriptions::of($this->actingCustomer->get())
            ->whereKey($subscription)
            ->firstOrFail();

        /*
         * Read from the catalogue, never from the request beyond their ids.
         * A price is money, and a price supplied by the client is a price the
         * client chose.
         */
        $plan = Plan::query()->findOrFail($request->planId());
        $price = PlanPrice::query()->findOrFail($request->priceId());

        $outcome = $this->applyPlanChange->execute(
            subscription: $found,
            plan: $plan,
            price: $price,
            units: $request->units(),
            idempotencyKey: $request->idempotencyKey(),
        );

        $proration = $outcome->proration;

        return response()->json([
            'data' => [
                'subscription' => new SubscriptionResource($found->refresh()),
                // Both halves published, not just the net. A customer owed a
                // credit and charged a larger amount should see both numbers
                // rather than one figure they cannot check.
                'credit' => $proration->credit->jsonSerialize(),
                'charge' => $proration->charge->jsonSerialize(),
                'net' => $proration->net()->jsonSerialize(),
                'effective_at' => $proration->changeAt->toIso8601String(),
                'period_end' => $proration->periodEnd->toIso8601String(),

                /*
                 * The other half, said out loud. The money has moved and the
                 * machine has not yet changed; a response that reported only
                 * the billing would have the portal announce a completed
                 * upgrade while the customer's server is still the old size.
                 */
                'resize' => $outcome->awaitsInfrastructure() ? [
                    'job_id' => (string) $outcome->resizeJob?->getKey(),
                    'status' => $outcome->resizeJob?->status->value,
                ] : null,
                'awaits_infrastructure' => $outcome->awaitsInfrastructure(),
            ],
        ]);
    }

    /**
     * The plans this subscription may move to, priced.
     *
     * Every figure comes from the backend's own proration, through the same
     * call the confirmation makes. Nothing here is for a client to compute:
     * a portal that worked out a credit itself would be a second
     * implementation of this platform's money rules, in a language with one
     * numeric type, on a device whose clock the customer sets.
     *
     * Refused plans are included with their reasons rather than omitted. The
     * commonest reason a customer opens this screen is to move to something
     * smaller, and the commonest refusal is exactly that — a screen that
     * silently hid the plan they were looking for would tell them nothing.
     */
    public function planOptions(Request $request, string $subscription): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $found = CustomerSubscriptions::of($this->actingCustomer->get())
            ->whereKey($subscription)
            ->firstOrFail();

        return response()->json([
            'data' => array_map(
                static fn (PlanChangeQuote $quote): array => (new PlanChangeQuoteResource($quote))->toArray($request),
                $this->quotePlanChange->options($found),
            ),
        ]);
    }
}
