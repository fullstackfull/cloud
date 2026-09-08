<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Notifications\Http\Controllers\NotificationController;

/*
 * notifications — the customer's inbox.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer.
 *
 * Preferences are NOT here. They belong to a person rather than an account —
 * two people on one customer read different mail — so they sit with the other
 * /me routes, outside the group that resolves an acting customer.
 *
 * Read and acknowledge only. There is deliberately no endpoint that creates a
 * notification and none that deletes one: notifications are raised by the
 * platform's own events, and a customer who could delete the record of their
 * service being terminated would be deleting the only copy they have of what
 * happened to their account.
 */

Route::prefix('notifications')->as('notifications.')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])->name('index');

    Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read_all');

    /*
     * After read-all, so that the literal segment is matched before the
     * parameter. The other order would make "read-all" a notification id,
     * which 404s for a reason nobody would guess from the URL.
     */
    Route::post('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
});
