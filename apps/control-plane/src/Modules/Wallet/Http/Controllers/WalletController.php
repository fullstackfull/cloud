<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Wallet\Application\Actions\GetCustomerWalletBalances;
use Lynomia\Modules\Wallet\Http\Requests\ListWalletTransactionsRequest;
use Lynomia\Modules\Wallet\Http\Resources\WalletBalanceResource;
use Lynomia\Modules\Wallet\Http\Resources\WalletTransactionResource;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use Lynomia\Modules\Wallet\Infrastructure\Queries\CustomerWalletTransactions;

/**
 * The customer-facing wallet surface. Read-only, and that is the design.
 *
 * There is no route here by which a customer credits their own balance. Money
 * enters a wallet in exactly two ways — a payment the provider confirmed
 * server-to-server, or an adjustment an operator posted under their own name —
 * and both are somebody else's surface. A POST /wallet/topup that moved a
 * balance would be a client telling the platform it has been paid, which is
 * the same mistake as a browser reporting its own payment succeeded: stored
 * value bought with a bookmark. Topping up is POST /invoices/{id}/payments,
 * and the overpayment lands in the wallet through SettleInvoice.
 *
 * Nor is there a debit route. A wallet is spent by settling an invoice, and
 * the floor check that keeps a balance from going negative lives inside
 * WalletLedger under a row lock; a second entry point into that would be a
 * second place for the check to be forgotten. Spending it is
 * `POST /invoices/{invoice}/wallet-credit`, which lives on the invoice
 * controller because what it answers with is an invoice — and because a
 * module's HTTP layer is not another module's to reach into.
 *
 * Two rules hold across both methods.
 *
 * **Scoping, not checking.** Balances come from the acting customer's own
 * `wallets` relation and ledger entries are reached through a join from that
 * customer to their wallets, so another tenant's row is not in the result set
 * to be filtered out afterwards. No `where('customer_id', ...)` at a call site
 * to forget, and no `abort_unless(...)` after a fetch that already had the row.
 *
 * **No id is accepted from the caller.** Neither method takes a path
 * parameter, and the request refuses a `wallet_id`; the only selector is a
 * currency code, which can name nothing outside the acting account. There is
 * consequently no id here to enumerate.
 *
 * No transaction lives in this class, and nothing here writes.
 */
final class WalletController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly GetCustomerWalletBalances $balances,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * The acting customer's balances.
     *
     * A list rather than a number, because a customer transacting in two
     * currencies has two balances and no total: adding them would need a rate,
     * and a converted balance is not one anything in the platform would honour.
     * `meta.account_currency` names the one a single-currency client wants.
     *
     * The figure is the cached `wallets.balance_minor` — the same column
     * WalletLedger locks and enforces a debit against — never a fresh sum of
     * the ledger. See GetCustomerWalletBalances for why the platform must not
     * have two ways of arriving at a balance.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $customer = $this->actingCustomer->get();
        $balances = $this->balances->execute($customer);

        return response()->json([
            'data' => WalletBalanceResource::collection($balances),
            'meta' => [
                'account_currency' => strtoupper($customer->currency),
                'currencies' => count($balances),
            ],
        ]);
    }

    /**
     * The acting customer's ledger, newest first.
     *
     * Every currency by default, since a statement that silently omitted one
     * of the customer's balances would be a statement that does not add up;
     * `?currency=` narrows it to one of their own wallets.
     */
    public function transactions(ListWalletTransactionsRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'billing.view');

        $kind = $request->kind();
        $currency = $request->currency();

        /** @var LengthAwarePaginator<int, WalletTransaction> $entries */
        $entries = CustomerWalletTransactions::of($this->actingCustomer->get())
            // An entry has no currency of its own, only its wallet's, so the
            // wallet is loaded rather than lazily fetched once per row.
            ->with('wallet')
            ->when($kind !== null, fn ($query) => $query->where('wallet_transactions.kind', $kind->value))
            ->when($currency !== null, fn ($query) => $query->where('wallets.currency', $currency))
            // created_at has one-second resolution on this table, so it cannot
            // order entries written in the same second on its own. The ULID is
            // lexicographically ordered by generation time and breaks the tie
            // in insertion order, which is what keeps paging stable: without
            // it a row can appear on two pages or on none.
            ->orderByDesc('wallet_transactions.created_at')
            ->orderByDesc('wallet_transactions.id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => WalletTransactionResource::collection($entries->getCollection()),
            'meta' => [
                'page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
                'max_per_page' => ListWalletTransactionsRequest::MAX_PER_PAGE,
            ],
        ]);
    }
}
