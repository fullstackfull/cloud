<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Billing\Http\Controllers\InvoiceController;
use Lynomia\Modules\Billing\Http\Controllers\SubscriptionController;

/*
 * billing — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * No route accepts a customer id, in the path, the query string or the body.
 * The account is resolved once by the `customer` middleware from the
 * authenticated principal, and `{invoice}` and `{subscription}` are resolved
 * *through* that account — hence plain string parameters rather than implicit
 * model binding, which would fetch the row globally before anyone could scope
 * it, and would 403-or-404 on an id the caller was never allowed to name.
 *
 * **No invoice PDF.** The platform does not render an invoice document, so
 * there is no GET /invoices/{invoice}/pdf. A route that returned an empty file
 * would be worse than a missing one: a client would ship a download button
 * that hands customers a broken file, and nobody would find out until an
 * accountant asked for one.
 *
 * **Nothing writes to an invoice.** No POST, no PATCH, no void, no refund. An
 * invoice is frozen once issued, and every figure on it is moved by the
 * settlement, void and refund actions on the platform's own side. Paying one
 * is the Payments module's surface.
 *
 * **No renewal or plan-change route.** RenewSubscription is the worker's
 * entry point and refuses anything the due-for-renewal scope excludes;
 * ChangeSubscriptionPlan prices a proration that has to be reviewed as a
 * purchase, not slipped in under a subscriptions route. Neither is published
 * here.
 *
 * ---------------------------------------------------------------------------
 * Cancellation
 * ---------------------------------------------------------------------------
 *
 * POST /subscriptions/{subscription}/cancel takes an optional `immediately`
 * flag. Absent or false schedules the end for the period the customer has
 * already paid for; true ends it now. No idempotency key: a cancellation
 * neither spends money nor provisions hardware, and repeating one converges —
 * the scheduled form keeps the first date it recorded, and the immediate form
 * goes through the state machine, which returns an already-cancelled
 * subscription unchanged.
 */

Route::prefix('invoices')->as('invoices.')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show');
});

Route::prefix('subscriptions')->as('subscriptions.')->group(function (): void {
    Route::get('/', [SubscriptionController::class, 'index'])->name('index');
    Route::get('{subscription}', [SubscriptionController::class, 'show'])->name('show');

    /*
     * A tighter limiter than the shared `throttle:api` ceiling the group
     * already applies. Cancelling is safe to repeat, but it is also the one
     * destructive thing on this surface, and a flood of distinct subscription
     * ids — the shape of a stolen session being used to tear an account down —
     * should be expensive.
     */
    Route::post('{subscription}/cancel', [SubscriptionController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('cancel');
});
