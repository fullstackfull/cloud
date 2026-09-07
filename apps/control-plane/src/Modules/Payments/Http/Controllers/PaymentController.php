<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerInvoices;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Http\Controllers\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Payments\Http\Requests\ListPaymentsRequest;
use Lynomia\Modules\Payments\Http\Requests\StartPaymentRequest;
use Lynomia\Modules\Payments\Http\Resources\PaymentResource;
use Lynomia\Modules\Payments\Http\Resources\StartedPaymentResource;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Queries\CustomerTransactions;

/**
 * The customer-facing payment surface.
 *
 * Three rules hold across every method here.
 *
 * **A payment is never settled by a request.** `store` opens a payment and
 * returns what the provider needs the browser to do next. It cannot mark an
 * invoice paid, and there is deliberately no sibling method — and no route
 * anywhere in the application — through which a client can report that a
 * payment succeeded. Settlement arrives from the provider, server to server,
 * through the webhook endpoint; a browser's word about money is a claim, and a
 * platform that provisions on a claim can be paid with a bookmark.
 *
 * **Scoping, not checking.** Every invoice and every payment this controller
 * touches is fetched through a relation hanging off the acting customer, so
 * another tenant's id matches no row and the request 404s. There is no
 * `where('customer_id')` at a call site to forget, and no
 * `abort_unless($row->customer_id === ...)` after the fact — a check that runs
 * after an unscoped fetch has already had the row in hand.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms that the row exists. The within-account permission check therefore
 * runs before any lookup, so its 403 depends on the caller's role and never on
 * whether the id was real.
 *
 * No transaction lives in this class: opening a payment is an action, so a
 * dunning job can make the same attempt without going through HTTP.
 */
final class PaymentController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly StartInvoicePayment $startPayment,
    ) {}

    /**
     * The acting customer's payments, newest first.
     */
    public function index(ListPaymentsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $status = $request->status();
        $kind = $request->kind();

        /** @var LengthAwarePaginator<int, Transaction> $payments */
        $payments = CustomerTransactions::of($this->acting->get())
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind->value))
            // The ULID tie-breaks payments recorded in the same millisecond,
            // so paging is stable and a row cannot appear on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => PaymentResource::collection($payments->getCollection()),
            'meta' => [
                'page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
                'max_per_page' => ListPaymentsRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One payment.
     */
    public function show(Request $request, string $payment): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $found = CustomerTransactions::of($this->acting->get())
            ->whereKey($payment)
            ->firstOrFail();

        return (new PaymentResource($found))->response();
    }

    /**
     * Starts paying an invoice.
     *
     * What is collected comes from the invoice, never from the body: the
     * request contributes a return url and nothing else that touches money.
     *
     * Always 201, including for a repeat against an invoice that already has a
     * payment open — the repeat reuses the same attempt and the same provider
     * idempotency key, so it describes the payment that already exists rather
     * than creating a second one. Reading first to choose between 200 and 201
     * would add a second idempotency lookup in front of the one the action
     * already performs under a row lock, and the status code is not worth two
     * mechanisms that can disagree.
     */
    public function store(StartPaymentRequest $request, string $invoice): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $found = CustomerInvoices::of($this->acting->get())
            ->whereKey($invoice)
            ->firstOrFail();

        $started = $this->startPayment->execute($found, $request->returnUrl());

        return (new StartedPaymentResource($started))
            ->response()
            ->setStatusCode(201);
    }

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }
}
