<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\SharedHosting\Http\Controllers\HostingController;
use Lynomia\Modules\SharedHosting\Http\Controllers\WordPressController;
use Lynomia\Modules\SharedHosting\Http\Controllers\WordPressCopyController;

/*
 * hosting — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Why {account} is a plain string
 * ---------------------------------------------------------------------------
 *
 * No implicit model binding anywhere on this file. Binding would fetch the
 * account globally, before anything could scope it, and the controller would
 * then be holding another tenant's row — its username, its domain, its node —
 * while it decided what to say about it. Every id here is resolved *through*
 * the acting customer, so an id from another account is not found rather than
 * found-and-refused.
 *
 * No route accepts a customer id, a node id or a node slug, in the path, the
 * query string or the body. The account is resolved once by the `customer`
 * middleware from the authenticated principal.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * **No create and no destroy.** An account comes into existence by buying
 * hosting: POST /orders places the order and CreateHostingAccountHandler
 * builds it, committing the node's capacity in the same transaction that
 * writes the row. A second door into that handler would place accounts onto
 * nodes that nobody is billed for. Terminating is the same in reverse — it
 * releases the data irreversibly and is gated on a retention window measured
 * from the moment service stopped, which is a commercial decision rather than
 * a DELETE on a username.
 *
 * **No suspend and no unsuspend.** Both actions exist and neither belongs to
 * the customer: suspension is what dunning does to an account, and an endpoint
 * that let a customer unsuspend their own hosting would undo it.
 *
 * **No password reset yet.** changePassword() exists at the provider and the
 * platform deliberately keeps no copy of what it sets, so publishing it means
 * deciding where the new password goes — shown once, mailed, or set by the
 * customer — and rate-limiting it as the credential-reset endpoint it is.
 * That is a surface of its own, not a line on this file.
 *
 * **Nothing that polls a node.** The list, show and usage endpoints read the
 * platform's record. An endpoint that asked the panel on every request would
 * give every customer a way to put load on the shared machine their
 * neighbours' sites run on, and would answer 502 whenever a node was busy.
 */

Route::prefix('hosting')->as('hosting.')->group(function (): void {
    Route::get('/', [HostingController::class, 'index'])->name('index');
    Route::get('{account}', [HostingController::class, 'show'])->name('show');

    /*
     * Each call mints a live, one-time session into a control panel. Left on
     * the general API limit a loop here would open hundreds of panel sessions
     * a minute against a shared node — both a credential-spraying surface and
     * a way to make WHM's session store somebody else's problem. At ten a
     * minute it is a person clicking a button.
     */
    Route::post('{account}/sso', [HostingController::class, 'sso'])
        ->middleware('throttle:10,1,hosting-sso:')
        ->name('sso');

    Route::get('{account}/usage', [HostingController::class, 'usage'])->name('usage');
});

/*
 * WordPress sites.
 *
 * Their own prefix rather than nested under an account, because a site exists
 * before its account does: the row is created when the order is placed so the
 * customer has something to watch progress against, and the account is built
 * afterwards.
 */
Route::prefix('wordpress')->as('wordpress.')->group(function (): void {
    Route::get('sites', [WordPressController::class, 'index'])->name('sites.index');

    Route::post('sites', [WordPressController::class, 'store'])
        // Each one builds an account on a shared node and installs software on
        // it. A loop here is a loop of real machines.
        ->middleware('throttle:10,1,wordpress-order:')
        ->name('sites.store');

    Route::get('sites/{site}', [WordPressController::class, 'show'])
        ->whereUlid('site')
        ->name('sites.show');

    /*
     * Copies and the push back, for the panels whose toolkit can. The site
     * row's `copies` block says which, and the routes refuse with the same
     * reason for the others. The push is throttled hardest: each one
     * overwrites a live site.
     */
    Route::post('sites/{site}/staging', [WordPressCopyController::class, 'staging'])
        ->whereUlid('site')
        ->middleware('throttle:5,1,wordpress-copy:')
        ->name('sites.staging');

    Route::post('sites/{site}/clones', [WordPressCopyController::class, 'clone'])
        ->whereUlid('site')
        ->middleware('throttle:5,1,wordpress-copy:')
        ->name('sites.clones');

    Route::get('sites/{site}/push/impact', [WordPressCopyController::class, 'pushImpact'])
        ->whereUlid('site')
        ->name('sites.push_impact');

    Route::post('sites/{site}/push', [WordPressCopyController::class, 'push'])
        ->whereUlid('site')
        ->middleware('throttle:3,1,wordpress-push:')
        ->name('sites.push');

    Route::get('sites/{site}/operations', [WordPressCopyController::class, 'operations'])
        ->whereUlid('site')
        ->name('sites.operations');
});
