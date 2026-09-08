<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Billing\Http\Requests\ListInvoicesRequest;
use Lynomia\Modules\Billing\Http\Requests\PayInvoiceFromCreditRequest;
use Lynomia\Modules\Billing\Http\Resources\InvoiceResource;
use Lynomia\Modules\Billing\Http\Resources\WalletCreditQuoteResource;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerInvoices;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Wallet\Application\Actions\PayInvoiceFromWallet;
use Lynomia\Modules\Wallet\Application\Actions\QuoteWalletCredit;

/**
 * The customer-facing invoice surface. Read-only, and deliberately so.
 *
 * An invoice is a document, not a resource a client edits: its number, dates
 * and billing snapshot are frozen the moment it leaves draft, and every figure
 * on it is moved by the settlement, void and refund actions rather than by
 * anything reachable from here. Paying one is the Payments module's business.
 *
 * Two rules hold across both methods, and neither is checked twice.
 *
 * **Scoping, not checking.** Every invoice is fetched through
 * `CustomerInvoices::of($actingCustomer)`, so another tenant's id matches no
 * row and the request 404s. There is no `where('customer_id')` at a call site
 * to forget, and no `abort_unless($invoice->customer_id === ...)` afterwards —
 * a check that runs after an unscoped fetch has already had the document.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs, and a 403
 * confirms that the row exists. Answering identically for "no such invoice"
 * and "not your invoice" is what stops the API being an enumeration oracle.
 */
final class InvoiceController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly QuoteWalletCredit $quote,
        private readonly PayInvoiceFromWallet $payFromWallet,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The acting customer's invoices, newest first.
     */
    public function index(ListInvoicesRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $status = $request->status();

        /** @var LengthAwarePaginator<int, Invoice> $invoices */
        $invoices = CustomerInvoices::of($this->actingCustomer->get())
            // Counted, not loaded: a page of twenty-five invoices does not
            // need every line of every one of them, and GET /invoices/{id} is
            // where the lines live.
            ->withCount('items')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            // Issued order where it exists, falling back to creation for the
            // drafts that have no issue date yet. The ULID tie-breaks two
            // invoices issued in the same millisecond, so paging is stable and
            // a row cannot appear on two pages.
            ->orderByRaw('COALESCE(issued_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => InvoiceResource::collection($invoices->getCollection()),
            'meta' => [
                'page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
                'max_per_page' => ListInvoicesRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One invoice, its lines, its tax and what is still owed.
     *
     * `amount_due` comes from the generated column via the resource; nothing
     * here re-derives it. There is no PDF: the platform does not render an
     * invoice document, and a route that returned an empty file would be worse
     * than no route at all.
     */
    public function show(Request $request, string $invoice): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $found = CustomerInvoices::of($this->actingCustomer->get())
            ->with('items')
            ->whereKey($invoice)
            ->firstOrFail();

        return (new InvoiceResource($found))->response();
    }

    /**
     * What paying this invoice from stored credit would do.
     *
     * A quote, not a promise: between reading this and paying, a renewal can
     * spend the balance, so the payment recomputes everything under a lock.
     * This exists so a screen can show three honest numbers — what the
     * customer has, what this invoice would take, what would still be owed.
     */
    public function walletCreditQuote(Request $request, string $invoice): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        return (new WalletCreditQuoteResource(
            $this->quote->execute($this->actingCustomer->get(), $this->scopedInvoice($invoice)),
        ))->response();
    }

    /**
     * Spend stored credit against this invoice.
     *
     * `billing.pay` rather than `billing.view`: this moves money the account
     * holds. A technical contact who may rebuild a server may not spend the
     * balance, and a read-only member may not either.
     *
     * There is no `amount` in the request. How much is applied is decided from
     * the balance and the amount due, both read under a lock; a figure from the
     * client would be a second opinion about something with one right answer.
     */
    public function payFromWalletCredit(PayInvoiceFromCreditRequest $request, string $invoice): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $settlement = $this->payFromWallet->execute(
            $this->actingCustomer->get(),
            $this->scopedInvoice($invoice),
            $request->idempotencyKey(),
        );

        return (new InvoiceResource($settlement->invoice->fresh(['items'])))->response();
    }

    /**
     * Scoped, not checked: an invoice belonging to another account is not in
     * the result set to be authorised against, so it answers 404 — and a draft,
     * which this surface never shows, answers the same.
     */
    private function scopedInvoice(string $id): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = CustomerInvoices::of($this->actingCustomer->get())->whereKey($id)->firstOrFail();

        return $invoice;
    }
}
