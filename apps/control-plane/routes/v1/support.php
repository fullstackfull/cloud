<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Support\Http\Controllers\TicketController;

/*
 * support — customer surface.
 *
 * Inside the business group, so authenticated, verified, throttled and
 * resolved to one account before anything here runs. Every ticket is reached
 * through a `where` on that account, so an id from another one is a 404.
 *
 * A customer may close a ticket and may not resolve one. Resolved is the
 * support team's opinion that the problem is solved; closed is the account
 * saying it is finished with the conversation. They are different statements
 * and only one of them is the customer's to make.
 */

Route::prefix('support')->as('support.')->group(function (): void {
    Route::get('tickets', [TicketController::class, 'index'])->name('index');

    Route::post('tickets', [TicketController::class, 'store'])
        // Tighter than the shared ceiling: every ticket is a notification
        // somebody has to read, and the resource being spent is a support
        // team's attention.
        ->middleware('throttle:10,1')
        ->name('store');

    Route::get('tickets/{ticket}', [TicketController::class, 'show'])
        ->whereUlid('ticket')
        ->name('show');

    Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])
        ->whereUlid('ticket')
        ->middleware('throttle:30,1')
        ->name('reply');

    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])
        ->whereUlid('ticket')
        ->name('close');

    Route::get('attachments/{attachment}', [TicketController::class, 'download'])
        ->whereUlid('attachment')
        ->name('attachments.download');
});
