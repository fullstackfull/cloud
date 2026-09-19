<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Payments\Http\Controllers\ControlledGatewayController;
use Lynomia\Modules\Payments\Http\Controllers\PaymentController;

/*
 * payments — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * Three routes, and the shape of the list is the security property.
 *
 * A customer can *start* a payment and *read* what happened to one. There is
 * no route by which a client can tell the platform that a payment succeeded —
 * no /confirm, no /return, no PATCH on a payment's status — and there must
 * never be one. A browser coming back from a provider controls its own URL, so
 * "?status=succeeded" is a claim rather than evidence, and a service
 * provisioned on it has been bought with a bookmark. Confirmation reaches the
 * platform through the signed webhook at /webhooks/payments/{provider}, which
 * is verified against the provider's signature before it is believed; the
 * browser-return path exists too, but it is the platform asking the provider
 * (ConfirmPaymentFromReturn), not the browser telling the platform, and it is
 * not exposed here.
 */

Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])
    ->name('invoices.payments.store');

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

/*
|--------------------------------------------------------------------------
| The controlled gateway
|--------------------------------------------------------------------------
|
| The fake provider's own payment page, for tests and for development. It is
| refused whenever a real provider is configured and refused outright in
| production, and it settles nothing itself: approving here makes the provider
| send the platform a signed webhook, and that webhook is what settles the
| invoice. See ControlledGatewayController for why every step is real.
|
*/
Route::prefix('fake-gateway/payments')->as('fake_gateway.')->group(function (): void {
    Route::get('{reference}', [ControlledGatewayController::class, 'show'])->name('show');

    Route::post('{reference}/approve', [ControlledGatewayController::class, 'approve'])
        ->middleware('throttle:30,1,controlled-gateway:')
        ->name('approve');

    Route::post('{reference}/decline', [ControlledGatewayController::class, 'decline'])
        ->middleware('throttle:30,1,controlled-gateway:')
        ->name('decline');

    Route::post('{reference}/confirm', [ControlledGatewayController::class, 'confirm'])
        ->middleware('throttle:30,1,controlled-gateway:')
        ->name('confirm');
});
