<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Activity\Http\Controllers\ActivityController;

/*
 * activity — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * One route, and no identifier in it
 * ---------------------------------------------------------------------------
 *
 * `GET /activity` — the acting customer's own history. There is deliberately
 * no `/activity/{customer}` and no `?customer=`: the only account this
 * endpoint can read is the one the token belongs to, so there is no parameter
 * an attacker could point somewhere else. A feed that unions nine modules is
 * the most attractive endpoint on the customer surface, and the cheapest
 * defence is having nothing to tamper with.
 *
 * Chosen over `/me/activity` to match this API's existing shape: the customer
 * surface already namespaces by resource (`/services`, `/invoices`,
 * `/notifications`) rather than by subject, and `/me` appears only on the
 * identity endpoints that describe the signed-in user. Only one of the two
 * exists.
 *
 * Per-resource history stays where Wave 3 left it, at
 * `GET /services/{service}/events`. The two share their translation of source
 * rows into customer words — one `ActivityProjection` — so a reboot cannot
 * read "restarted" on the machine's page and something else in the account
 * feed. They do not share a query, because they answer different questions:
 * that one is "what happened to this server", scoped by a path parameter; this
 * one is "what happened to my account", scoped by the token alone.
 *
 * `throttle:reads` rather than the default: a customer watching an operation
 * will refresh this page more often than they submit anything, and the
 * mutation limiter would answer 429 while they were doing nothing wrong.
 */
Route::middleware('throttle:reads')->group(function (): void {
    Route::get('activity', [ActivityController::class, 'index'])
        ->name('activity.index');
});
