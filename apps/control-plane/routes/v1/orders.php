<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Orders\Http\Controllers\OrderController;

/*
 * orders — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Idempotency
 * ---------------------------------------------------------------------------
 *
 * POST /orders REQUIRES an `Idempotency-Key` request header. The header is the
 * only source: a body field of the same name is overwritten by it, because two
 * places to put one key is two answers to "is this the same purchase?" and the
 * wrong answer is a second charge.
 *
 *     Idempotency-Key: 7f9c1a2e-checkout-2026-09-06
 *
 * The key is scoped to the acting customer by a unique index on
 * (customer_id, idempotency_key). Repeating a request with the same key returns
 * the order the first one created — the same id, the same number, the same
 * total — rather than placing a second one. A missing key is a 422, not a
 * silently generated one: a server-invented key is unique per request, which
 * would make every retry a new purchase.
 *
 * Both the first response and every repeat are 201. The endpoint does not read
 * the key ahead of PlaceOrder to tell the two apart, because that second lookup
 * could disagree with the constraint that actually enforces uniqueness.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * No route accepts a customer id, in the path, the query string or the body.
 * The account is resolved once by the `customer` middleware from the
 * authenticated principal, and `{order}` is resolved through that account —
 * hence a plain string parameter rather than implicit model binding, which
 * would fetch the row globally before anyone could scope it.
 *
 * There is no PATCH: an order is a record of a purchase, not a document. And
 * there is no refund route — cancelling a paid order is a refund, a different
 * operation with different authorisation, and POST /orders/{order}/cancel
 * refuses one with `order.already_paid`.
 */

Route::prefix('orders')->as('orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->name('index');

    /*
     * Checkout spends money, so it carries a tighter limiter than the shared
     * `throttle:api` ceiling the group already applies. The idempotency key
     * makes a retry safe; the limiter makes a flood of *distinct* baskets — the
     * shape of a stolen session being cashed out — expensive.
     */
    Route::post('/', [OrderController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('store');

    Route::get('{order}', [OrderController::class, 'show'])->name('show');

    Route::post('{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('cancel');
});
