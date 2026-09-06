<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Wallet\Http\Controllers\WalletController;

/*
 * wallet — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Two routes, and the shortness of the list is the security property
 * ---------------------------------------------------------------------------
 *
 * **Nothing here writes.** A customer cannot credit their own wallet, and
 * there is deliberately no POST /wallet/topup, no PATCH on a balance and no
 * adjustment route. Money enters a wallet from a payment the provider
 * confirmed server-to-server, or from an operator's attributed adjustment on
 * the administrative surface; a route that let a client move its own stored
 * value would be a route that sells services for nothing. Topping up is
 * POST /invoices/{invoice}/payments — the surplus reaches the wallet through
 * SettleInvoice, after the money has actually arrived.
 *
 * Nor is there a debit route: a wallet is spent by settling an invoice, and
 * the balance floor is enforced inside WalletLedger under a row lock. A second
 * way in would be a second place for that check to be missing.
 *
 * **No id is accepted anywhere.** No {wallet}, no {transaction}, and no wallet
 * id in a query string. Which wallets are read follows from the acting
 * customer, so there is no id on this surface to enumerate and no cross-tenant
 * lookup to get wrong. `?currency=` selects among the caller's own wallets and
 * can name nothing outside the account.
 *
 * **No reconciliation route.** WalletLedger::reconcile() compares the cached
 * balance against the ledger and reports drift. That is an operator's alarm —
 * it names divergent entry ids and holds a write lock for the length of a
 * wallet's ledger scan — and neither the alarm nor the lock belongs on a
 * surface a customer can call in a loop.
 */

Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');

Route::get('wallet/transactions', [WalletController::class, 'transactions'])
    ->name('wallet.transactions.index');
