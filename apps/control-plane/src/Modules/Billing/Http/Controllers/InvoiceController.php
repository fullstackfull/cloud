<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceSettlement;
use Lynomia\Modules\Billing\Http\Requests\ListInvoicesRequest;
use Lynomia\Modules\Billing\Http\Requests\PayInvoiceFromCreditRequest;
use Lynomia\Modules\Billing\Http\Resources\InvoiceResource;
use Lynomia\Modules\Billing\Http\Resources\WalletCreditQuoteResource;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerInvoices;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Application\Actions\WithdrawAnUnpaidPlanChange;
use Lynomia\Modules\Wallet\Application\Actions\PayInvoiceFromWallet;
use Lynomia\Modules\Wallet\Application\Actions\QuoteWalletCredit;

/**
 * The customer-facing invoice surface. Nothing here edits an invoice.
 *
 * An invoice is a document, not a resource a client edits: its number, dates
 * and billing snapshot are frozen the moment it leaves draft, and every figure
 * on it is moved by the settlement, void and refund actions rather than by
 * anything reachable from here. Paying one by card is the Payments module's
 * business. The two acts here go through those actions: paying from the
 * wallet (a settlement), and withdrawing the unpaid plan change an invoice
 * bills (the platform's own void, as a renewal's lapse takes it).
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
        private readonly WithdrawAnUnpaidPlanChange $withdraw,
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
            /*
             * Whether each invoice bills a recorded plan change, read once for
             * the page. The resource asks the plan-change questions behind
             * `is_payable` and `plan_change_withdrawable` only of an open
             * invoice that does - at most one per subscription, since no
             * change can be made while one is open - where it used to ask
             * them of every open subscription invoice, row by row.
             */
            ->addSelect([InvoiceResource::BILLS_A_PLAN_CHANGE => DB::table('subscription_plan_changes')
                ->selectRaw('count(*) > 0')
                ->whereColumn('subscription_plan_changes.proration_invoice_id', 'invoices.id')])
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
            /*
             * The document, whole: its lines, the payments recorded against
             * it — including the ones that failed — and any wallet credit
             * that moved. A customer looking at an invoice is asking what they
             * bought and what happened to their money, and answering half of
             * that is what sends them to support.
             */
            ->with([
                'items',
                'transactions' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                // An entry's amount is in its wallet's currency, so the
                // wallet comes with it (WalletTransaction::amount()).
                'walletCredits' => fn ($query) => $query->with('wallet')->orderBy('created_at')->orderBy('id'),
            ])
            ->whereKey($invoice)
            ->firstOrFail();

        return InvoiceResource::document($found)->response();
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

        $scoped = $this->scopedInvoice($invoice);
        // Read before the audit's transaction opens, never under it (B2).
        $runs = $this->payFromWallet->whatTheMachineRuns($scoped);

        $settlement = app(RecordActAtomically::class)->execute(
            fn (): InvoiceSettlement => $this->payFromWallet->execute(
                $this->actingCustomer->get(),
                $scoped,
                $request->idempotencyKey(),
                $runs,
            ),
            /*
             * Nothing is recorded when nothing moved, which here means one
             * thing only: the replay of a request that already succeeded. The
             * first copy wrote this row; a second saying credit was spent
             * again would misstate the balance's history for anybody reading
             * it afterwards.
             */
            fn (InvoiceSettlement $result): ?AuditedAct => $result->movedNothing() ? null : new AuditedAct(
                action: AuditAction::WalletCreditSpent,
                subject: $result->invoice,
                customerId: (string) $result->invoice->customer_id,
                context: [
                    'applied_minor' => $result->applied->minorUnits(),
                    'currency' => $result->applied->currency(),
                    'invoice_number' => $result->invoice->number,
                    'settled' => $result->invoice->status->value,
                ],
            ),
        );

        return InvoiceResource::document(
            $settlement->invoice->fresh(['items', 'transactions', 'walletCredits.wallet'])
        )->response();
    }

    /**
     * Withdraw the unpaid plan change this invoice bills.
     *
     * The customer's way out of a plan change they have not paid for - above
     * all one that can no longer be delivered, whose payment is refused
     * (`invoice.plan_change_not_deliverable`) and whose open invoice held
     * every other change of plan (N2 / X7-2). What the invoice holds goes back
     * to the wallet, the invoice is voided, and the subscription goes back to
     * the plan and the price it came from (WithdrawAnUnpaidPlanChange). Not
     * an edit of the invoice: the platform's own void, the one a renewal's
     * lapse takes. `billing.pay`, the permission a plan change needs.
     */
    public function withdrawPlanChange(Request $request, string $invoice): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        $found = $this->scopedInvoice($invoice);
        $actor = $request->user() instanceof User ? $request->user() : null;

        /** @var array{invoice: Invoice, returned_to_wallet_minor: int} $withdrawn */
        $withdrawn = app(RecordActAtomically::class)->execute(
            fn (): array => $this->withdraw->execute($found, $actor),
            static fn (array $result): AuditedAct => new AuditedAct(
                action: AuditAction::InvoiceVoided,
                subject: $result['invoice'],
                customerId: (string) $result['invoice']->customer_id,
                context: [
                    'reason' => WithdrawAnUnpaidPlanChange::REASON,
                    'number' => $result['invoice']->number,
                    'total_minor' => $result['invoice']->total_minor,
                    'currency' => $result['invoice']->currency,
                    'returned_to_wallet_minor' => $result['returned_to_wallet_minor'],
                ],
            ),
        );

        return InvoiceResource::document(
            $withdrawn['invoice']->fresh(['items', 'transactions', 'walletCredits.wallet']) ?? $withdrawn['invoice']
        )->response();
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
